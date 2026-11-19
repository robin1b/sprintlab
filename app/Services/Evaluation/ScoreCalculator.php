<?php

namespace App\Services\Evaluation;

use App\Enums\AssessmentStatus;
use App\Enums\ReviewStatus;
use App\Enums\ReviewVerdict;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\PullRequest;
use App\Models\ReviewAssessment;
use App\Models\Sprint;

/**
 * The scores of a sprint, from 0 to 100 per area. Calculated here, never by
 * the model: the AI only gives ratings (1 to 5) that are converted below.
 * An area is null when there is nothing to judge it on.
 */
class ScoreCalculator
{
    public const AREAS = ['delivery', 'code_quality', 'review', 'communication', 'process', 'understanding'];

    /**
     * The four areas that PHP can judge by itself.
     *
     * @return array{delivery: int|null, code_quality: int|null, review: int|null, process: int|null}
     */
    public function fromSprint(Sprint $sprint): array
    {
        return [
            'delivery' => $this->delivery($sprint),
            'code_quality' => $this->codeQuality($sprint),
            'review' => $this->review($sprint),
            'process' => $this->process($sprint),
        ];
    }

    /**
     * A rating from 1 to 5 as a score: 1 is 0, 3 is 50, 5 is 100.
     */
    public function ratingToScore(int $rating): int
    {
        return (int) round((max(1, min(5, $rating)) - 1) / 4 * 100);
    }

    /**
     * The weighted average of the areas that were judged, or null when none was.
     *
     * @param  array<string, int|null>  $scores
     */
    public function overall(array $scores): ?int
    {
        $weights = (array) config('sprintlab.evaluation.weights');
        $sum = 0.0;
        $total = 0.0;

        foreach ($weights as $area => $weight) {
            $score = $scores[$area] ?? null;

            if ($score === null) {
                continue;
            }

            $sum += $score * $weight;
            $total += $weight;
        }

        return $total > 0 ? (int) round($sum / $total) : null;
    }

    /**
     * Released tickets that are done count for 80 points, finishing the sprint
     * in time for 20.
     */
    private function delivery(Sprint $sprint): ?int
    {
        $released = $sprint->tickets()->whereNotNull('released_at')->count();

        if ($released === 0) {
            return null;
        }

        $done = $sprint->tickets()->whereNotNull('released_at')->where('status', TicketStatus::Done)->count();
        $inTime = $sprint->completed_at !== null && $sprint->completed_at->lessThanOrEqualTo($sprint->deadline_at);

        return (int) round($done / $released * 80 + ($inTime ? 20 : 0));
    }

    /**
     * How the team lead's reviews went. A pull request approved at the first
     * go is worth 100; every extra round of requested changes costs 15 points.
     * A pull request that still has open requested changes is capped at 50.
     */
    private function codeQuality(Sprint $sprint): ?int
    {
        $tickets = $sprint->tickets()
            ->where('type', '!=', TicketType::Review)
            ->whereNotNull('pull_request_id')
            ->with('pullRequest.reviews')
            ->get();

        $scores = [];

        foreach ($tickets as $ticket) {
            $pullRequest = $ticket->pullRequest;

            // The same pull request on two tickets counts once.
            if ($pullRequest === null || isset($scores[$pullRequest->id])) {
                continue;
            }

            $score = $this->pullRequestScore($pullRequest);

            if ($score !== null) {
                $scores[$pullRequest->id] = $score;
            }
        }

        return $scores === [] ? null : (int) round(array_sum($scores) / count($scores));
    }

    private function pullRequestScore(PullRequest $pullRequest): ?int
    {
        $posted = $pullRequest->reviews
            ->where('status', ReviewStatus::Posted)
            ->sortBy('id')
            ->values();

        if ($posted->isEmpty()) {
            return null;
        }

        $verdicts = $posted->pluck('verdict')->all();

        if ($verdicts[0] === ReviewVerdict::Approve) {
            return 100;
        }

        $rounds = count(array_filter($verdicts, fn ($verdict): bool => $verdict === ReviewVerdict::RequestChanges));
        $score = max(40, 85 - 15 * max(0, $rounds - 1));

        if ($verdicts[count($verdicts) - 1] === ReviewVerdict::RequestChanges) {
            $score = min($score, 50);
        }

        return $score;
    }

    /**
     * The average of the best assessment of each review ticket. A review
     * ticket that was never assessed (for example because the AI was off)
     * does not count against the user.
     */
    private function review(Sprint $sprint): ?int
    {
        $ticketIds = $sprint->tickets()->where('type', TicketType::Review)->pluck('id');

        $best = ReviewAssessment::query()
            ->whereIn('ticket_id', $ticketIds)
            ->where('status', AssessmentStatus::Done)
            ->whereNotNull('score')
            ->get()
            ->groupBy('ticket_id')
            ->map(fn ($assessments): int => (int) $assessments->max('score'));

        return $best->isEmpty() ? null : (int) round($best->avg());
    }

    /**
     * Share of the finished work tickets that went through a pull request.
     */
    private function process(Sprint $sprint): ?int
    {
        $done = $sprint->tickets()
            ->where('type', '!=', TicketType::Review)
            ->where('status', TicketStatus::Done);

        $total = (clone $done)->count();

        if ($total === 0) {
            return null;
        }

        return (int) round((clone $done)->whereNotNull('pull_request_id')->count() / $total * 100);
    }
}
