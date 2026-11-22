<?php

namespace App\Services\Review;

use App\Ai\Agents\ReviewAssessor;
use App\Enums\AiFeature;
use App\Enums\AssessmentStatus;
use App\Enums\Colleague;
use App\Enums\ReviewVerdict;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Message;
use App\Models\ReviewAssessment;
use App\Models\Ticket;
use App\Services\Ai\Budget;
use App\Services\Ai\UsageRecorder;
use App\Services\GitHub\GitHubApp;
use App\Services\Sprint\TicketService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;
use Throwable;

/**
 * Judges the user's review of a colleague's pull request against the
 * problems that were planted in it, and lets Sarah send feedback.
 */
class UserReviewAssessmentService
{
    private const WEIGHTS = ['high' => 3, 'medium' => 2, 'low' => 1];

    // A review that approves a pull request with a serious problem cannot pass.
    private const APPROVED_WITH_MISSED_HIGH_CAP = 49;

    private const MAX_FALSE_POSITIVES = 5;

    // Only this many false positives cost points (5 each).
    private const MAX_PENALISED_FALSE_POSITIVES = 3;

    public function __construct(
        private readonly GitHubApp $github,
        private readonly TicketService $tickets,
        private readonly Budget $budget,
        private readonly UsageRecorder $usage,
    ) {}

    public function assess(Ticket $ticket, int $githubReviewId): ?ReviewAssessment
    {
        if (! config('sprintlab.ai.enabled') || $ticket->type !== TicketType::Review) {
            return null;
        }

        $pullRequest = $ticket->pullRequest;
        $planted = $ticket->scenarioTicket->planted_issues ?? [];

        if ($pullRequest === null || $planted === []) {
            return null;
        }

        $assessment = ReviewAssessment::firstOrCreate(
            ['github_review_id' => $githubReviewId],
            [
                'ticket_id' => $ticket->id,
                'pull_request_id' => $pullRequest->id,
                'reviewer_login' => '',
                'status' => AssessmentStatus::Pending,
            ],
        );

        // Already handled (a retried or duplicated job): never assess or message twice.
        if (in_array($assessment->status, [AssessmentStatus::Done, AssessmentStatus::Skipped], true)) {
            return $assessment;
        }

        try {
            $client = $this->github->installation($pullRequest->repository->installation->installation_id);
            $repository = $pullRequest->repository->full_name;

            $review = $client->review($repository, $pullRequest->number, $githubReviewId);
            $comments = array_values(array_filter(
                $client->reviewComments($repository, $pullRequest->number, $githubReviewId),
                fn (array $comment): bool => trim($comment['body']) !== '',
            ));

            $verdict = $this->verdict($review['state']);

            $assessment->update(['reviewer_login' => $review['user_login'], 'verdict' => $verdict]);

            $skipReason = match (true) {
                $review['state'] === 'PENDING' => 'The review is not submitted yet.',
                trim($review['body']) === '' && $comments === [] => 'The review has no text and no comments.',
                ! $this->budget->allows() => 'Monthly AI budget reached.',
                default => null,
            };

            if ($skipReason !== null) {
                $assessment->update(['status' => AssessmentStatus::Skipped, 'error' => $skipReason]);

                return $assessment;
            }

            $diff = DiffSummary::fromFiles(
                $client->pullRequestFiles($repository, $pullRequest->number),
                (int) config('sprintlab.ai.max_diff_characters'),
            );

            $response = ReviewAssessor::make(level: $ticket->sprint->scenario->level)->prompt(
                $this->prompt($planted, $pullRequest->title, $verdict, $review['body'], $comments, $diff),
                provider: Lab::Anthropic,
                model: (string) config('sprintlab.ai.review_model'),
            );

            $this->usage->record(
                AiFeature::Assessment,
                $response->meta->model,
                $response->usage->inputTokens,
                $response->usage->outputTokens,
                $ticket->sprint->user,
                $ticket->sprint,
            );

            $issues = $this->cleanIssues($planted, (array) ($response['issues'] ?? []));
            $falsePositives = $this->cleanFalsePositives((array) ($response['false_positives'] ?? []));
            $clarity = $this->clamp($response['clarity'] ?? 0);
            $tone = $this->clamp($response['tone'] ?? 0);

            $severities = [];
            foreach ($planted as $index => $known) {
                $severities[] = ['severity' => $known['severity'], 'found' => $issues[$index]['found']];
            }

            $score = self::score($severities, $clarity, $tone, count($falsePositives), $verdict);
            $feedback = trim((string) ($response['feedback'] ?? ''));

            // Saving, Sarah's message and the ticket move succeed or fail together,
            // so a retry can never send the feedback twice.
            DB::transaction(function () use ($assessment, $ticket, $verdict, $issues, $falsePositives, $clarity, $tone, $score, $feedback, $response): void {
                $assessment->update([
                    'status' => AssessmentStatus::Done,
                    'verdict' => $verdict,
                    'issues' => $issues,
                    'false_positives' => $falsePositives,
                    'clarity' => $clarity,
                    'tone' => $tone,
                    'score' => $score,
                    'feedback' => $feedback,
                    'model' => $response->meta->model,
                    'input_tokens' => $response->usage->inputTokens,
                    'output_tokens' => $response->usage->outputTokens,
                    'error' => null,
                ]);

                if ($feedback !== '') {
                    Message::create([
                        'sprint_id' => $ticket->sprint_id,
                        'colleague' => Colleague::Sarah,
                        'from_user' => false,
                        'body' => $feedback,
                        'ticket_id' => $ticket->id,
                    ]);
                }

                if ($score >= (int) config('sprintlab.ai.review_pass_score')) {
                    try {
                        $this->tickets->move($ticket, TicketStatus::Done);
                    } catch (DomainException) {
                        // The sprint is paused: the assessment stays, the ticket waits.
                    }
                }
            });
        } catch (Throwable $exception) {
            $assessment->update([
                'status' => AssessmentStatus::Failed,
                'error' => mb_substr($exception->getMessage(), 0, 1000),
            ]);

            throw $exception;
        }

        return $assessment;
    }

    /**
     * The score from 0 to 100 of a review. Calculated here, never by the model.
     *
     * Found problems are worth 70 points, weighted by severity (high 3, medium
     * 2, low 1). Clarity and tone are worth 15 points each. Every false
     * positive costs 5 points, at most 15. Approving a pull request that still
     * has a high-severity problem caps the score below the pass mark.
     *
     * @param  list<array{severity: string, found: bool}>  $issues
     */
    public static function score(array $issues, int $clarity, int $tone, int $falsePositives, ?ReviewVerdict $verdict): int
    {
        $total = 0;
        $found = 0;
        $missedHigh = false;

        foreach ($issues as $issue) {
            $weight = self::WEIGHTS[$issue['severity']] ?? 1;
            $total += $weight;

            if ($issue['found']) {
                $found += $weight;
            } elseif ($issue['severity'] === 'high') {
                $missedHigh = true;
            }
        }

        $foundShare = $total === 0 ? 0 : $found / $total;
        $penalty = min($falsePositives, self::MAX_PENALISED_FALSE_POSITIVES) * 5;

        $score = (int) round($foundShare * 70 + $clarity / 5 * 15 + $tone / 5 * 15 - $penalty);
        $score = max(0, min(100, $score));

        if ($verdict === ReviewVerdict::Approve && $missedHigh) {
            $score = min($score, self::APPROVED_WITH_MISSED_HIGH_CAP);
        }

        return $score;
    }

    private function verdict(string $state): ?ReviewVerdict
    {
        return match ($state) {
            'APPROVED' => ReviewVerdict::Approve,
            'CHANGES_REQUESTED' => ReviewVerdict::RequestChanges,
            'COMMENTED', 'DISMISSED' => ReviewVerdict::Comment,
            default => null,
        };
    }

    /**
     * Only the known ids count, in the order of the planted list. What the
     * model leaves out counts as not found.
     *
     * @param  list<array{id: string, file?: string, description: string, severity: string}>  $planted
     * @param  array<array-key, mixed>  $answer
     * @return list<array{id: string, found: bool, evidence: string}>
     */
    private function cleanIssues(array $planted, array $answer): array
    {
        $byId = [];

        foreach ($answer as $item) {
            // The first answer for an id wins.
            $byId[(string) data_get($item, 'id', '')] ??= $item;
        }

        $issues = [];

        foreach ($planted as $known) {
            $item = $byId[$known['id']] ?? null;
            $found = $item !== null && filter_var(data_get($item, 'found'), FILTER_VALIDATE_BOOLEAN);

            $issues[] = [
                'id' => $known['id'],
                'found' => $found,
                'evidence' => $found ? trim((string) data_get($item, 'evidence', '')) : '',
            ];
        }

        return $issues;
    }

    /**
     * @param  array<array-key, mixed>  $answer
     * @return list<string>
     */
    private function cleanFalsePositives(array $answer): array
    {
        $texts = [];

        foreach ($answer as $text) {
            if (is_string($text) && trim($text) !== '') {
                $texts[] = trim($text);
            }
        }

        return array_slice($texts, 0, self::MAX_FALSE_POSITIVES);
    }

    private function clamp(mixed $value): int
    {
        return max(1, min(5, (int) $value));
    }

    /**
     * @param  list<array{id: string, file?: string, description: string, severity: string}>  $planted
     * @param  list<array{path: string, line: int|null, body: string}>  $comments
     */
    private function prompt(array $planted, string $title, ?ReviewVerdict $verdict, string $reviewText, array $comments, DiffSummary $diff): string
    {
        $known = implode("\n", array_map(
            fn (array $issue): string => sprintf(
                '- id: %s | severity: %s | file: %s | %s',
                $issue['id'],
                $issue['severity'],
                $issue['file'] ?? '(not specific)',
                $issue['description'],
            ),
            $planted,
        ));

        $verdictText = match ($verdict) {
            ReviewVerdict::Approve => 'approved',
            ReviewVerdict::RequestChanges => 'changes requested',
            default => 'commented',
        };

        $text = trim($reviewText) !== '' ? Str::limit(trim($reviewText), 6000) : '(no text)';

        $inline = $comments === [] ? '(none)' : implode("\n", array_map(
            fn (array $comment): string => sprintf(
                '- %s: %s',
                $comment['line'] === null ? $comment['path'] : "{$comment['path']}:{$comment['line']}",
                Str::limit(trim($comment['body']), 1500),
            ),
            $comments,
        ));

        $prompt = <<<TEXT
        Known problems in the pull request:
        {$known}

        Pull request: {$title}

        The developer's review
        Verdict: {$verdictText}

        Review text:
        {$text}

        Inline comments:
        {$inline}

        The diff of the pull request. The number in front of a line is its line number in the new version of the file.

        {$diff->text}
        TEXT;

        if ($diff->truncated) {
            $prompt .= "\n\n(The diff was cut off because it is too long.)";
        }

        return $prompt;
    }
}
