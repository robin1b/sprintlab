<?php

namespace Tests\Feature\Evaluation;

use App\Enums\AssessmentStatus;
use App\Enums\ReviewStatus;
use App\Enums\ReviewVerdict;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Sprint;
use App\Services\Evaluation\ScoreCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\EvaluationFixtures;
use Tests\TestCase;

class ScoreAreasTest extends TestCase
{
    use EvaluationFixtures, RefreshDatabase;

    private function scores(Sprint $sprint): array
    {
        return (new ScoreCalculator)->fromSprint($sprint);
    }

    public function test_a_sprint_without_anything_to_judge_has_only_null_areas(): void
    {
        $sprint = $this->completedSprint();

        $this->assertSame(['delivery' => null, 'code_quality' => null, 'review' => null, 'process' => null], $this->scores($sprint));
    }

    // delivery

    public function test_delivery_is_done_over_released_times_80_plus_20_in_time(): void
    {
        $sprint = $this->completedSprint(inTime: true);
        $this->addTicket($sprint, status: TicketStatus::Done);
        $this->addTicket($sprint, status: TicketStatus::Done);
        $this->addTicket($sprint, status: TicketStatus::InProgress);
        $this->addTicket($sprint, status: TicketStatus::Todo);

        // 2 of 4 done: 40 + 20
        $this->assertSame(60, $this->scores($sprint)['delivery']);
    }

    public function test_delivery_gets_no_bonus_after_the_deadline(): void
    {
        $sprint = $this->completedSprint(inTime: false);
        $this->addTicket($sprint, status: TicketStatus::Done);

        $this->assertSame(80, $this->scores($sprint)['delivery']);
    }

    public function test_delivery_ignores_tickets_that_were_never_released(): void
    {
        $sprint = $this->completedSprint();
        $this->addTicket($sprint, status: TicketStatus::Done);
        $this->addTicket($sprint, status: TicketStatus::Todo, released: false);

        $this->assertSame(100, $this->scores($sprint)['delivery']);
    }

    public function test_delivery_has_no_bonus_when_the_completion_time_is_missing(): void
    {
        $sprint = $this->completedSprint();
        $sprint->update(['completed_at' => null]);
        $this->addTicket($sprint, status: TicketStatus::Done);

        $this->assertSame(80, $this->scores($sprint)['delivery']);
    }

    // code quality

    public function test_code_quality_is_100_when_the_first_review_approved(): void
    {
        $sprint = $this->completedSprint();
        $this->addTicket($sprint, pullRequest: $this->pullRequestWithReviews([ReviewVerdict::Approve]));

        $this->assertSame(100, $this->scores($sprint)['code_quality']);
    }

    public function test_code_quality_stays_100_when_a_later_review_asks_for_changes_after_a_first_approval(): void
    {
        $sprint = $this->completedSprint();
        $this->addTicket($sprint, pullRequest: $this->pullRequestWithReviews([ReviewVerdict::Approve, ReviewVerdict::RequestChanges, ReviewVerdict::Approve]));

        $this->assertSame(100, $this->scores($sprint)['code_quality']);
    }

    public function test_code_quality_drops_15_points_per_extra_round_of_requested_changes(): void
    {
        $one = $this->completedSprint();
        $this->addTicket($one, pullRequest: $this->pullRequestWithReviews([ReviewVerdict::RequestChanges, ReviewVerdict::Approve]));
        $two = $this->completedSprint();
        $this->addTicket($two, pullRequest: $this->pullRequestWithReviews([ReviewVerdict::RequestChanges, ReviewVerdict::RequestChanges, ReviewVerdict::Approve]));
        $four = $this->completedSprint();
        $this->addTicket($four, pullRequest: $this->pullRequestWithReviews([
            ReviewVerdict::RequestChanges, ReviewVerdict::RequestChanges, ReviewVerdict::RequestChanges, ReviewVerdict::RequestChanges, ReviewVerdict::Approve,
        ]));
        $many = $this->completedSprint();
        $this->addTicket($many, pullRequest: $this->pullRequestWithReviews(array_merge(
            array_fill(0, 8, ReviewVerdict::RequestChanges),
            [ReviewVerdict::Approve],
        )));

        $this->assertSame(85, $this->scores($one)['code_quality']);
        $this->assertSame(70, $this->scores($two)['code_quality']);
        $this->assertSame(40, $this->scores($four)['code_quality']);
        // Never below 40.
        $this->assertSame(40, $this->scores($many)['code_quality']);
    }

    public function test_code_quality_is_capped_at_50_when_the_newest_review_still_requests_changes(): void
    {
        $sprint = $this->completedSprint();
        $this->addTicket($sprint, pullRequest: $this->pullRequestWithReviews([ReviewVerdict::RequestChanges]));

        $this->assertSame(50, $this->scores($sprint)['code_quality']);
    }

    public function test_code_quality_is_the_average_over_pull_requests_and_ignores_the_rest(): void
    {
        $sprint = $this->completedSprint();
        $this->addTicket($sprint, pullRequest: $this->pullRequestWithReviews([ReviewVerdict::Approve]));
        $this->addTicket($sprint, pullRequest: $this->pullRequestWithReviews([ReviewVerdict::RequestChanges, ReviewVerdict::Approve]));
        // Not counted: only a pending review, only a skipped review, a review ticket, no review at all.
        $this->addTicket($sprint, pullRequest: $this->pullRequestWithReviews([ReviewVerdict::RequestChanges], ReviewStatus::Pending));
        $this->addTicket($sprint, pullRequest: $this->pullRequestWithReviews([ReviewVerdict::RequestChanges], ReviewStatus::Skipped));
        $this->addTicket($sprint, TicketType::Review, pullRequest: $this->pullRequestWithReviews([ReviewVerdict::RequestChanges]));
        $this->addTicket($sprint, pullRequest: $this->pullRequestWithReviews([]));
        $this->addTicket($sprint);

        // (100 + 85) / 2 = 92.5
        $this->assertSame(93, $this->scores($sprint)['code_quality']);
    }

    public function test_code_quality_counts_a_shared_pull_request_once(): void
    {
        $sprint = $this->completedSprint();
        $shared = $this->pullRequestWithReviews([ReviewVerdict::Approve]);
        $this->addTicket($sprint, pullRequest: $shared);
        $this->addTicket($sprint, pullRequest: $shared);
        $this->addTicket($sprint, pullRequest: $this->pullRequestWithReviews([ReviewVerdict::RequestChanges]));

        // (100 + 50) / 2, not (100 + 100 + 50) / 3
        $this->assertSame(75, $this->scores($sprint)['code_quality']);
    }

    public function test_a_review_with_only_a_comment_verdict_does_not_lower_the_score(): void
    {
        $sprint = $this->completedSprint();
        $this->addTicket($sprint, pullRequest: $this->pullRequestWithReviews([ReviewVerdict::Comment, ReviewVerdict::Approve]));

        $this->assertSame(85, $this->scores($sprint)['code_quality']);
    }

    // review

    public function test_review_is_the_average_of_the_best_assessment_per_review_ticket(): void
    {
        $sprint = $this->completedSprint();
        $first = $this->addTicket($sprint, TicketType::Review);
        $second = $this->addTicket($sprint, TicketType::Review);
        $this->addAssessment($first, 40);
        $this->addAssessment($first, 80);
        $this->addAssessment($second, 60);

        // (80 + 60) / 2
        $this->assertSame(70, $this->scores($sprint)['review']);
    }

    public function test_review_ignores_assessments_that_are_not_done(): void
    {
        $sprint = $this->completedSprint();
        $ticket = $this->addTicket($sprint, TicketType::Review);
        $this->addAssessment($ticket, 90, AssessmentStatus::Skipped);
        $this->addAssessment($ticket, null, AssessmentStatus::Failed);
        $this->addAssessment($ticket, 30);

        $this->assertSame(30, $this->scores($sprint)['review']);
    }

    public function test_review_is_null_without_review_tickets_or_assessments(): void
    {
        $sprint = $this->completedSprint();
        $this->addTicket($sprint, TicketType::Bug);
        $this->assertNull($this->scores($sprint)['review']);

        $this->addTicket($sprint, TicketType::Review);
        $this->assertNull($this->scores($sprint)['review']);
    }

    public function test_review_does_not_use_assessments_of_another_sprint(): void
    {
        $sprint = $this->completedSprint();
        $this->addTicket($sprint, TicketType::Review);
        $other = $this->addTicket($this->completedSprint(), TicketType::Review);
        $this->addAssessment($other, 100);

        $this->assertNull($this->scores($sprint)['review']);
    }

    // process

    public function test_process_is_the_share_of_done_work_tickets_with_a_pull_request(): void
    {
        $sprint = $this->completedSprint();
        $this->addTicket($sprint, pullRequest: $this->pullRequestWithReviews([ReviewVerdict::Approve]));
        $this->addTicket($sprint);
        $this->addTicket($sprint);
        $this->addTicket($sprint, pullRequest: $this->pullRequestWithReviews([]));
        // Not counted: not done, and a review ticket.
        $this->addTicket($sprint, status: TicketStatus::InProgress);
        $this->addTicket($sprint, TicketType::Review);

        $this->assertSame(50, $this->scores($sprint)['process']);
    }

    public function test_process_is_null_without_done_work_tickets(): void
    {
        $sprint = $this->completedSprint();
        $this->addTicket($sprint, TicketType::Review);
        $this->addTicket($sprint, status: TicketStatus::InReview);

        $this->assertNull($this->scores($sprint)['process']);
    }

    public function test_process_is_0_when_no_done_ticket_has_a_pull_request(): void
    {
        $sprint = $this->completedSprint();
        $this->addTicket($sprint);

        $this->assertSame(0, $this->scores($sprint)['process']);
    }
}
