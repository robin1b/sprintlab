<?php

namespace App\Services\Review;

use App\Ai\Agents\PullRequestReviewer;
use App\Enums\Colleague;
use App\Enums\PullRequestState;
use App\Enums\ReviewStatus;
use App\Enums\ReviewVerdict;
use App\Models\PullRequest;
use App\Models\Review;
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
    public function __construct(private readonly GitHubApp $github) {}

    public function review(PullRequest $pullRequest, string $headSha, Colleague $colleague = Colleague::Sarah): ?Review
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
                $this->writeReview($review, $pullRequest, $diff, $colleague);
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

    private function writeReview(Review $review, PullRequest $pullRequest, DiffSummary $diff, Colleague $colleague): void
    {
        $response = PullRequestReviewer::make(colleague: $colleague)->prompt(
            $this->prompt($pullRequest, $diff),
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

    private function prompt(PullRequest $pullRequest, DiffSummary $diff): string
    {
        $description = trim((string) $pullRequest->body) !== '' ? trim((string) $pullRequest->body) : '(no description)';

        $prompt = <<<TEXT
        Pull request #{$pullRequest->number}: {$pullRequest->title}
        Author: {$pullRequest->author_login}
        Branch: {$pullRequest->head_ref} into {$pullRequest->base_ref}

        Description:
        {$description}

        Changed files. The number in front of a line is its line number in the new version of the file.

        {$diff->text}
        TEXT;

        if ($diff->truncated) {
            $prompt .= "\n\nThe diff was cut off because it is too long. Mention in your summary that you only looked at the first part.";
        }

        return $prompt;
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
