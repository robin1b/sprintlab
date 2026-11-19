<?php

namespace Tests\Feature\Sprint;

use App\Enums\PullRequestState;
use App\Enums\TicketStatus;
use App\Jobs\ReviewPullRequest;
use App\Models\PullRequest;
use App\Models\Sprint;
use App\Models\User;
use App\Services\Sprint\PullRequestTicketLinker;
use App\Services\Sprint\SprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Support\GitHubFixtures;
use Tests\Support\SprintFixtures;
use Tests\TestCase;

class PullRequestTicketLinkerTest extends TestCase
{
    use GitHubFixtures, RefreshDatabase, SprintFixtures;

    private User $user;

    private Sprint $sprint;

    private PullRequest $pullRequest;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([ReviewPullRequest::class]);

        $this->user = User::factory()->create(['name' => 'Robin Broos']);
        $this->sprint = $this->startDemoSprint($this->user);
        $this->pullRequest = $this->createPullRequest($this->user);
        $this->pullRequest->update(['head_ref' => 'wk-1-fix-double-approval', 'title' => 'Fix double approval']);
    }

    private function link(string $action): void
    {
        app(PullRequestTicketLinker::class)->link($this->pullRequest->refresh(), $action);
    }

    private function statusOf(string $key): TicketStatus
    {
        return $this->ticket($this->sprint, $key)->status;
    }

    public function test_an_opened_pull_request_links_the_ticket_and_moves_it_to_review(): void
    {
        $this->link('opened');

        $ticket = $this->ticket($this->sprint, 'WK-1');
        $this->assertSame($this->pullRequest->id, $ticket->pull_request_id);
        $this->assertSame(TicketStatus::InReview, $ticket->status);
        $this->assertSame(TicketStatus::Todo, $this->statusOf('WK-2'));
    }

    public function test_the_key_can_be_in_the_title_in_any_case(): void
    {
        $this->pullRequest->update(['head_ref' => 'fix/double-approval', 'title' => 'wk-2: Filter by budget']);

        $this->link('opened');

        $this->assertSame(TicketStatus::InReview, $this->statusOf('WK-2'));
        $this->assertSame(TicketStatus::Todo, $this->statusOf('WK-1'));
    }

    public function test_a_longer_key_does_not_match_a_shorter_one(): void
    {
        $this->pullRequest->update(['head_ref' => 'wk-12-something', 'title' => 'Something']);

        $this->link('opened');

        $this->assertNull($this->ticket($this->sprint, 'WK-1')->pull_request_id);
    }

    public function test_reopened_and_ready_for_review_also_move_to_review(): void
    {
        foreach (['reopened', 'ready_for_review'] as $action) {
            $this->ticket($this->sprint, 'WK-1')->update(['status' => TicketStatus::InProgress]);

            $this->link($action);

            $this->assertSame(TicketStatus::InReview, $this->statusOf('WK-1'));
        }
    }

    public function test_a_draft_pull_request_does_not_move_the_ticket_to_review(): void
    {
        $this->pullRequest->update(['draft' => true]);

        $this->link('opened');

        $this->assertSame(TicketStatus::Todo, $this->statusOf('WK-1'));
        $this->assertSame($this->pullRequest->id, $this->ticket($this->sprint, 'WK-1')->pull_request_id);
    }

    public function test_a_new_push_does_not_change_the_status(): void
    {
        $this->link('opened');
        $this->ticket($this->sprint, 'WK-1')->update(['status' => TicketStatus::InProgress]);

        $this->link('synchronize');

        $this->assertSame(TicketStatus::InProgress, $this->statusOf('WK-1'));
    }

    public function test_a_merged_pull_request_moves_the_ticket_to_done(): void
    {
        $this->link('opened');
        $this->pullRequest->update(['state' => PullRequestState::Merged]);

        $this->link('closed');

        $this->assertSame(TicketStatus::Done, $this->statusOf('WK-1'));
    }

    public function test_a_pull_request_closed_without_merging_sends_a_ticket_in_review_back(): void
    {
        $this->link('opened');
        $this->pullRequest->update(['state' => PullRequestState::Closed]);

        $this->link('closed');

        $this->assertSame(TicketStatus::InProgress, $this->statusOf('WK-1'));
    }

    public function test_closing_does_not_touch_a_ticket_that_is_not_in_review(): void
    {
        $this->ticket($this->sprint, 'WK-1')->update(['status' => TicketStatus::Done]);
        $this->pullRequest->update(['state' => PullRequestState::Closed]);

        $this->link('closed');

        $this->assertSame(TicketStatus::Done, $this->statusOf('WK-1'));
    }

    public function test_nothing_happens_without_a_current_sprint(): void
    {
        $this->sprint->delete();

        $this->link('opened');

        $this->assertSame(0, $this->sprint->tickets()->count());
    }

    public function test_nothing_happens_after_the_sprint_is_completed(): void
    {
        $this->sprint->update(['status' => 'completed']);

        $this->link('opened');

        $this->assertNull($this->ticket($this->sprint, 'WK-1')->pull_request_id);
    }

    public function test_nothing_happens_when_the_installation_has_no_user(): void
    {
        $this->pullRequest->repository->installation->update(['user_id' => null]);

        $this->link('opened');

        $this->assertNull($this->ticket($this->sprint, 'WK-1')->pull_request_id);
    }

    public function test_nothing_happens_when_no_ticket_matches(): void
    {
        $this->pullRequest->update(['head_ref' => 'fix/double-approval', 'title' => 'Fix double approval']);

        $this->link('opened');

        $this->assertSame(0, $this->sprint->tickets()->whereNotNull('pull_request_id')->count());
    }

    public function test_a_ticket_that_is_not_released_is_not_linked(): void
    {
        $this->pullRequest->update(['head_ref' => 'wk-3-repair-status']);

        $this->link('opened');

        $ticket = $this->ticket($this->sprint, 'WK-3');
        $this->assertNull($ticket->pull_request_id);
        $this->assertSame(TicketStatus::Todo, $ticket->status);
    }

    public function test_a_paused_sprint_keeps_the_link_but_not_the_status(): void
    {
        app(SprintService::class)->pause($this->sprint);

        $this->link('opened');

        $ticket = $this->ticket($this->sprint, 'WK-1');
        $this->assertSame($this->pullRequest->id, $ticket->pull_request_id);
        $this->assertSame(TicketStatus::Todo, $ticket->status);
    }

    public function test_the_webhook_links_the_pull_request(): void
    {
        $this->pullRequest->delete();
        $this->user->update(['github_id' => 4242]);

        $this->githubWebhook('installation', $this->installationPayload());
        $this->githubWebhook('pull_request', $this->pullRequestPayload('opened', [
            'title' => 'WK-1: fix double approval',
            'head' => ['ref' => 'wk-1-fix-double-approval'],
        ]), 'delivery-2');

        $ticket = $this->ticket($this->sprint, 'WK-1');
        $this->assertSame(PullRequest::sole()->id, $ticket->pull_request_id);
        $this->assertSame(TicketStatus::InReview, $ticket->status);
    }
}
