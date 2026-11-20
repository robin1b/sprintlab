<?php

namespace App\Services\Review;

use App\Ai\Agents\PullRequestReviewer;
use App\Enums\AiFeature;
use App\Enums\Colleague;
use App\Enums\PullRequestState;
use App\Enums\ReviewStatus;
use App\Enums\ReviewVerdict;
use App\Enums\TicketType;
use App\Models\PullRequest;
use App\Models\Review;
use App\Models\Ticket;
use App\Services\Ai\Budget;
use App\Services\Ai\UsageRecorder;
use App\Services\GitHub\GitHubApp;
use App\Services\GitHub\InstallationClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;
use Throwable;

/**
 * Lets an AI colleague review one commit of a pull request and posts the
 * review on GitHub.
 */
class PullRequestReviewService
{
    public function __construct(
        private readonly GitHubApp $github,
        private readonly Budget $budget,
        private readonly UsageRecorder $usage,
    ) {}

    public function review(PullRequest $pullRequest, string $headSha, ?Colleague $colleague = null): ?Review
    {
        if (! config('sprintlab.ai.enabled')) {
            return null;
        }

        // A newer commit was pushed in the meantime; that commit gets its own review.
        if ($pullRequest->head_sha !== $headSha
            || $pullRequest->state !== PullRequestState::Open
            || $pullRequest->draft) {
            return null;
        }

        $installation = $pullRequest->repository->installation;

        if ($installation->suspended_at !== null) {
            return null;
        }

        $ticket = $this->linkedTicket($pullRequest);

        // A colleague's pull request that the user reviews: nobody reviews it for them.
        if ($ticket?->type === TicketType::Review) {
            return null;
        }

        $colleague ??= $ticket?->scenarioTicket->reviewer ?? Colleague::Sarah;

        $review = Review::firstOrCreate(
            ['pull_request_id' => $pullRequest->id, 'head_sha' => $headSha, 'colleague' => $colleague],
            ['status' => ReviewStatus::Pending],
        );

        // Already handled (a retried or duplicated job): never post twice.
        if (in_array($review->status, [ReviewStatus::Posted, ReviewStatus::Skipped], true)) {
            return $review;
        }

        if ($this->dailyLimitReached($installation->id)) {
            return $this->skip($review, 'Daily review limit reached.');
        }

        // A saved answer needs no new call, so only a review without one is stopped.
        if ($review->verdict === null && ! $this->budget->allows()) {
            return $this->skip($review, 'Monthly AI budget reached.');
        }

        try {
            $client = $this->github->installation($installation->installation_id);
            $repository = $pullRequest->repository->full_name;

            $diff = DiffSummary::fromFiles(
                $client->pullRequestFiles($repository, $pullRequest->number),
                (int) config('sprintlab.ai.max_diff_characters'),
            );

            if ($diff->isEmpty()) {
                return $this->skip($review, 'No reviewable changes.');
            }

            // The model's answer is saved before posting. If posting fails, the
            // retry reuses it instead of paying for a second answer.
            if ($review->verdict === null) {
                $this->writeReview($review, $pullRequest, $diff, $colleague, $ticket);
            }

            $this->post($client, $review, $pullRequest, $diff, $colleague);
        } catch (Throwable $exception) {
            $review->update([
                'status' => ReviewStatus::Failed,
                'error' => Str::limit($exception->getMessage(), 1000),
            ]);

            throw $exception;
        }

        return $review;
    }

    /**
     * The ticket this pull request belongs to, if it is linked to one.
     */
    private function linkedTicket(PullRequest $pullRequest): ?Ticket
    {
        return Ticket::query()
            ->with(['scenarioTicket', 'sprint.scenario'])
            ->where('pull_request_id', $pullRequest->id)
            ->orderByDesc('id')
            ->first();
    }

    private function writeReview(Review $review, PullRequest $pullRequest, DiffSummary $diff, Colleague $colleague, ?Ticket $ticket): void
    {
        $scenario = $ticket?->sprint->scenario;

        $response = PullRequestReviewer::make(
            colleague: $colleague,
            level: $scenario->level ?? 'junior',
            briefing: $scenario?->briefing,
        )->prompt(
            $this->prompt($pullRequest, $diff, $ticket),
            provider: Lab::Anthropic,
            model: (string) config('sprintlab.ai.review_model'),
        );

        $comments = [];

        foreach ((array) ($response['comments'] ?? []) as $comment) {
            $path = (string) data_get($comment, 'path', '');
            $body = trim((string) data_get($comment, 'body', ''));

            if ($path !== '' && $body !== '') {
                $comments[] = ['path' => $path, 'line' => (int) data_get($comment, 'line', 0), 'body' => $body];
            }
        }

        $review->update([
            'verdict' => ReviewVerdict::tryFrom((string) ($response['verdict'] ?? '')) ?? ReviewVerdict::Comment,
            'summary' => trim((string) ($response['summary'] ?? '')),
            'comments' => array_slice($comments, 0, (int) config('sprintlab.ai.max_inline_comments')),
            'model' => $response->meta->model,
            'input_tokens' => $response->usage->inputTokens,
            'output_tokens' => $response->usage->outputTokens,
        ]);

        $this->usage->record(
            AiFeature::Review,
            $response->meta->model,
            $response->usage->inputTokens,
            $response->usage->outputTokens,
            $pullRequest->repository->installation->user,
        );
    }

    private function post(InstallationClient $client, Review $review, PullRequest $pullRequest, DiffSummary $diff, Colleague $colleague): void
    {
        $inline = [];
        $loose = [];

        // GitHub rejects the whole review when one comment points at a line
        // outside the diff, so those comments move into the review text.
        foreach ($review->comments ?? [] as $comment) {
            if ($diff->allows($comment['path'], $comment['line'])) {
                $inline[] = $comment;
            } else {
                $loose[] = $comment;
            }
        }

        $body = "**{$colleague->displayName()}** ({$colleague->role()})\n\n{$review->summary}";

        if ($loose !== []) {
            $body .= "\n\nOther remarks:\n".implode("\n", array_map(
                fn (array $comment): string => "- `{$comment['path']}`: {$comment['body']}",
                $loose,
            ));
        }

        $githubReviewId = $client->createReview(
            $pullRequest->repository->full_name,
            $pullRequest->number,
            $review->head_sha,
            ($review->verdict ?? ReviewVerdict::Comment)->githubEvent(),
            $body,
            $inline,
        );

        $review->update([
            'status' => ReviewStatus::Posted,
            'github_review_id' => $githubReviewId,
            'error' => null,
        ]);
    }

    /**
     * What the reviewer gets to read. The author's GitHub username is left
     * out on purpose: the privacy page promises it is not sent to the AI.
     */
    private function prompt(PullRequest $pullRequest, DiffSummary $diff, ?Ticket $ticket): string
    {
        $description = trim((string) $pullRequest->body) !== '' ? trim((string) $pullRequest->body) : '(no description)';

        $prompt = $this->ticketBlock($ticket)."\n\n".<<<TEXT
        Pull request #{$pullRequest->number}: {$pullRequest->title}
        Branch: {$pullRequest->head_ref} into {$pullRequest->base_ref}

        Description:
        {$description}

        Changed files. The number in front of a line is its line number in the new version of the file.

        {$diff->text}
        TEXT;

        if ($diff->truncated) {
            $prompt .= "\n\nThe diff was cut off because it is too long. Mention in your summary that you only looked at the first part.";
        }

        return $prompt.$this->earlierReviewBlock($pullRequest);
    }

    /**
     * What a colleague said about an older commit of this pull request. A
     * second review that forgot the first would repeat itself or contradict it.
     */
    private function earlierReviewBlock(PullRequest $pullRequest): string
    {
        $earlier = Review::query()
            ->where('pull_request_id', $pullRequest->id)
            ->where('head_sha', '!=', $pullRequest->head_sha)
            ->where('status', ReviewStatus::Posted)
            ->orderByDesc('id')
            ->first();

        if ($earlier === null) {
            return '';
        }

        $verdict = str_replace('_', ' ', ($earlier->verdict ?? ReviewVerdict::Comment)->value);

        $remarks = collect((array) $earlier->comments)
            ->map(fn (mixed $comment): string => '- '.data_get($comment, 'path').': '.Str::limit((string) data_get($comment, 'body'), 400))
            ->implode("\n");

        $block = "\n\nThis pull request was reviewed before, on an older commit, by {$earlier->colleague->displayName()}. The verdict was \"{$verdict}\" with this summary:\n{$earlier->summary}";

        if ($remarks !== '') {
            $block .= "\n\nRemarks on lines at the time:\n{$remarks}";
        }

        return $block."\n\nThe diff above is the pull request as it is now. Check first whether the earlier remarks were dealt with. Say so when they were, do not repeat a remark that was fixed, and name what is still open.";
    }

    /**
     * The assignment: the ticket as it stands now, with the criteria added during the sprint.
     */
    private function ticketBlock(?Ticket $ticket): string
    {
        if ($ticket === null) {
            return 'This pull request is not linked to a ticket on the board. Ask the author which ticket it belongs to: the ticket key belongs in the branch name or in the title.';
        }

        $criteria = collect($ticket->acceptance_criteria)
            ->map(fn (string $criterion): string => '- '.$criterion)
            ->implode("\n");

        if ($criteria === '') {
            $criteria = '- (none)';
        }

        $block = <<<TEXT
        Ticket {$ticket->key}: {$ticket->title}
        Type: {$ticket->type->value}. Reported by {$ticket->reporter->displayName()}.

        {$ticket->description}

        Acceptance criteria as they stand now (criteria can change during a sprint; this list is the current one):
        {$criteria}
        TEXT;

        $notes = trim((string) $ticket->scenarioTicket->review_notes);

        if ($notes !== '') {
            $block .= "\n\nNotes from the team for you as the reviewer. They are private: use them to judge the work, never quote them and never say that they exist.\n{$notes}";
        }

        return $block;
    }

    private function dailyLimitReached(int $installationId): bool
    {
        $limit = (int) config('sprintlab.ai.reviews_per_installation_per_day');

        $postedToday = Review::query()
            ->where('status', ReviewStatus::Posted)
            ->where('created_at', '>=', Carbon::now()->startOfDay())
            ->whereHas('pullRequest.repository', fn ($query) => $query->where('github_installation_id', $installationId))
            ->count();

        return $postedToday >= $limit;
    }

    private function skip(Review $review, string $reason): Review
    {
        $review->update(['status' => ReviewStatus::Skipped, 'error' => $reason]);

        return $review;
    }
}
