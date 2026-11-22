<?php

namespace Tests\Feature\Review;

use App\Enums\TicketType;
use App\Jobs\AssessUserReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Support\GitHubFixtures;
use Tests\Support\ReviewFixtures;
use Tests\TestCase;

class AssessUserReviewWebhookTest extends TestCase
{
    use GitHubFixtures, RefreshDatabase, ReviewFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([AssessUserReview::class]);
    }

    public function test_a_submitted_review_of_the_user_is_sent_for_assessment(): void
    {
        $ticket = $this->createReviewTicket();

        $this->githubWebhook('pull_request_review', $this->reviewPayload())->assertStatus(202);

        Bus::assertDispatchedTimes(AssessUserReview::class, 1);
        Bus::assertDispatched(
            AssessUserReview::class,
            fn (AssessUserReview $job) => $job->ticketId === $ticket->id && $job->githubReviewId === 777,
        );
    }

    public function test_a_review_written_by_a_bot_is_ignored(): void
    {
        $this->createReviewTicket();

        $this->githubWebhook('pull_request_review', $this->reviewPayload(overrides: [
            'review' => ['user' => ['login' => 'sprintlab[bot]', 'type' => 'Bot']],
        ]));

        Bus::assertNotDispatched(AssessUserReview::class);
    }

    public function test_a_review_of_another_user_is_ignored(): void
    {
        $this->createReviewTicket();

        $this->githubWebhook('pull_request_review', $this->reviewPayload(overrides: ['sender' => ['id' => 999]]));

        Bus::assertNotDispatched(AssessUserReview::class);
    }

    public function test_a_pull_request_that_is_not_linked_to_a_review_ticket_is_ignored(): void
    {
        $ticket = $this->createReviewTicket();
        $ticket->update(['type' => TicketType::Bug]);

        $this->githubWebhook('pull_request_review', $this->reviewPayload());

        Bus::assertNotDispatched(AssessUserReview::class);
    }

    public function test_an_unknown_pull_request_is_ignored(): void
    {
        $this->createReviewTicket();

        $this->githubWebhook('pull_request_review', $this->reviewPayload(overrides: ['pull_request' => ['number' => 99]]));

        Bus::assertNotDispatched(AssessUserReview::class);
    }

    public function test_other_actions_are_ignored(): void
    {
        $this->createReviewTicket();

        foreach (['edited', 'dismissed'] as $index => $action) {
            $this->githubWebhook('pull_request_review', $this->reviewPayload($action), "delivery-{$index}");
        }

        Bus::assertNotDispatched(AssessUserReview::class);
    }
}
