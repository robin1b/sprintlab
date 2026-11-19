<?php

namespace Tests\Support;

use App\Ai\Agents\EvaluationInterviewer;
use App\Ai\Agents\EvaluationWriter;
use App\Enums\AssessmentStatus;
use App\Enums\Colleague;
use App\Enums\PullRequestState;
use App\Enums\ReviewStatus;
use App\Enums\ReviewVerdict;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\GitHubInstallation;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Models\Review;
use App\Models\ReviewAssessment;
use App\Models\Scenario;
use App\Models\ScenarioTicket;
use App\Models\Sprint;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Helpers for the sprint evaluation tests. Nothing here calls the real API.
 */
trait EvaluationFixtures
{
    private int $evaluationCounter = 0;

    /**
     * A completed sprint, finished before its deadline unless told otherwise.
     */
    protected function completedSprint(?User $user = null, bool $inTime = true): Sprint
    {
        $user ??= User::factory()->create(['name' => 'Robin Broos']);
        $scenario = Scenario::factory()->create(['title' => 'Lease approvals', 'level' => 'junior']);

        return Sprint::factory()->for($user)->for($scenario)->completed()->create([
            'started_at' => Carbon::now()->subDays(3),
            'deadline_at' => Carbon::now()->addDays($inTime ? 4 : -1),
            'completed_at' => Carbon::now(),
        ]);
    }

    protected function addTicket(Sprint $sprint, TicketType $type = TicketType::Bug, TicketStatus $status = TicketStatus::Done, ?PullRequest $pullRequest = null, bool $released = true): Ticket
    {
        $scenarioTicket = $type === TicketType::Review
            ? ScenarioTicket::factory()->for($sprint->scenario)->withPlantedIssues([['id' => 'secret-id', 'description' => 'SECRET PLANTED PROBLEM', 'severity' => 'high']])->create()
            : ScenarioTicket::factory()->for($sprint->scenario)->create(['type' => $type]);

        return Ticket::factory()->for($sprint)->for($scenarioTicket)->create([
            'type' => $type,
            'status' => $status,
            'released_at' => $released ? Carbon::now()->subDay() : null,
            'pull_request_id' => $pullRequest?->id,
        ]);
    }

    /**
     * A pull request with posted reviews of Sarah, oldest first.
     *
     * @param  list<ReviewVerdict>  $verdicts
     */
    protected function pullRequestWithReviews(array $verdicts, ReviewStatus $status = ReviewStatus::Posted): PullRequest
    {
        $this->evaluationCounter++;
        $n = $this->evaluationCounter;

        $installation = GitHubInstallation::create(['installation_id' => 5000 + $n, 'account_login' => 'robin', 'account_id' => 4242]);
        $repository = Repository::create(['github_id' => 8000 + $n, 'github_installation_id' => $installation->id, 'full_name' => "robin/workkit-{$n}", 'private' => true]);

        $pullRequest = PullRequest::create([
            'repository_id' => $repository->id,
            'number' => $n,
            'title' => "Pull request {$n}",
            'body' => '',
            'author_login' => 'robin',
            'head_ref' => "fix/{$n}",
            'head_sha' => str_repeat('a', 40),
            'base_ref' => 'main',
            'state' => PullRequestState::Open,
            'draft' => false,
        ]);

        foreach ($verdicts as $index => $verdict) {
            $this->addReview($pullRequest, $verdict, $status, "Summary of review {$index} on pull request {$n}.", $index);
        }

        return $pullRequest;
    }

    protected function addReview(PullRequest $pullRequest, ReviewVerdict $verdict, ReviewStatus $status = ReviewStatus::Posted, string $summary = 'A summary.', int $commit = 0, Colleague $colleague = Colleague::Sarah): Review
    {
        return Review::create([
            'pull_request_id' => $pullRequest->id,
            'colleague' => $colleague,
            'head_sha' => str_pad((string) $commit, 40, 'b', STR_PAD_LEFT),
            'status' => $status,
            'verdict' => $verdict,
            'summary' => $summary,
        ]);
    }

    protected function addAssessment(Ticket $ticket, ?int $score, AssessmentStatus $status = AssessmentStatus::Done, ?string $feedback = null): ReviewAssessment
    {
        return ReviewAssessment::factory()->create([
            'ticket_id' => $ticket->id,
            'pull_request_id' => $ticket->pull_request_id ?? $this->pullRequestWithReviews([])->id,
            'status' => $status,
            'score' => $score,
            'feedback' => $feedback,
        ]);
    }

    /**
     * A sprint with one released bug ticket (done, with an approved pull
     * request), so that the PHP areas have something to judge.
     */
    protected function sprintWithWork(?User $user = null): Sprint
    {
        $sprint = $this->completedSprint($user);
        $this->addTicket($sprint, TicketType::Bug, TicketStatus::Done, $this->pullRequestWithReviews([ReviewVerdict::Approve]));

        return $sprint;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function interviewerAnswer(array $overrides = []): array
    {
        return array_replace([
            'communication' => 4,
            'communication_evidence' => 'You told Emily you were stuck on the lease endpoint.',
            'questions' => [
                'Why did you check the contract before approving?',
                'How did you handle the remark about the naming?',
                'What would you do differently next time?',
            ],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function writerAnswer(array $overrides = []): array
    {
        return array_replace([
            'answers' => [
                ['index' => 1, 'understanding' => 5, 'note' => 'Accurate and with trade-offs.'],
                ['index' => 2, 'understanding' => 3, 'note' => 'Roughly right.'],
                ['index' => 3, 'understanding' => 1, 'note' => 'No real answer.'],
            ],
            'strengths' => 'You kept the fix small.',
            'growth' => 'Write the test first.',
            'advice' => 'Add a test before you open the pull request.',
        ], $overrides);
    }

    protected function fakeInterviewer(?array $answer = null): void
    {
        EvaluationInterviewer::fake([$answer ?? $this->interviewerAnswer()]);
    }

    protected function fakeWriter(?array $answer = null): void
    {
        EvaluationWriter::fake([$answer ?? $this->writerAnswer()]);
    }
}
