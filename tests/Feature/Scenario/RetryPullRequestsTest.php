<?php

namespace Tests\Feature\Scenario;

use App\Enums\SprintStatus;
use App\Enums\TicketStatus;
use App\Jobs\OpenColleaguePullRequest;
use App\Models\SprintEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Support\ColleaguePullRequestFixtures;
use Tests\Support\GitHubFixtures;
use Tests\TestCase;

class RetryPullRequestsTest extends TestCase
{
    use ColleaguePullRequestFixtures, GitHubFixtures, RefreshDatabase;

    public function test_the_tick_queues_the_job_again_for_a_fired_event_without_a_pull_request(): void
    {
        Bus::fake();
        ['sprint' => $sprint, 'ticket' => $ticket, 'event' => $event] = $this->createPullRequestEvent();
        $this->fireEvent($sprint, $event);

        $this->artisan('sprintlab:tick')->expectsOutput('Queued 1 pull request retries.')->assertSuccessful();

        Bus::assertDispatchedTimes(OpenColleaguePullRequest::class, 1);
        Bus::assertDispatched(OpenColleaguePullRequest::class, fn (OpenColleaguePullRequest $job): bool => $job->ticketId === $ticket->id && $job->scenarioEventId === $event->id);
    }

    public function test_nothing_is_queued_when_the_event_has_not_fired(): void
    {
        Bus::fake();
        $this->createPullRequestEvent();

        $this->artisan('sprintlab:tick')->assertSuccessful();

        Bus::assertNotDispatched(OpenColleaguePullRequest::class);
    }

    public function test_nothing_is_queued_when_the_ticket_has_a_pull_request(): void
    {
        Bus::fake();
        ['sprint' => $sprint, 'ticket' => $ticket, 'event' => $event] = $this->createPullRequestEvent();
        $this->fireEvent($sprint, $event);
        $ticket->update(['pull_request_id' => $this->createPullRequest($sprint->user)->id]);

        $this->artisan('sprintlab:tick')->assertSuccessful();

        Bus::assertNotDispatched(OpenColleaguePullRequest::class);
    }

    public function test_nothing_is_queued_for_a_done_ticket(): void
    {
        Bus::fake();
        ['sprint' => $sprint, 'ticket' => $ticket, 'event' => $event] = $this->createPullRequestEvent();
        $this->fireEvent($sprint, $event);
        $ticket->update(['status' => TicketStatus::Done]);

        $this->artisan('sprintlab:tick')->assertSuccessful();

        Bus::assertNotDispatched(OpenColleaguePullRequest::class);
    }

    public function test_nothing_is_queued_for_a_paused_or_completed_sprint(): void
    {
        Bus::fake();
        ['sprint' => $sprint, 'event' => $event] = $this->createPullRequestEvent();
        $this->fireEvent($sprint, $event);

        foreach ([SprintStatus::Paused, SprintStatus::Completed] as $status) {
            $sprint->update(['status' => $status]);

            $this->artisan('sprintlab:tick')->assertSuccessful();
        }

        Bus::assertNotDispatched(OpenColleaguePullRequest::class);
    }

    public function test_an_event_that_fires_in_the_tick_is_queued_once(): void
    {
        Bus::fake();
        ['sprint' => $sprint, 'event' => $event] = $this->createPullRequestEvent();
        $sprint->scenario->scenarioEvents()->whereKey($event->id)->update(['delay_minutes' => 0]);
        SprintEvent::create(['sprint_id' => $sprint->id, 'scenario_event_id' => $event->id, 'due_at' => now()->subMinute()]);

        $this->artisan('sprintlab:tick')->assertSuccessful();

        Bus::assertDispatchedTimes(OpenColleaguePullRequest::class, 1);
    }
}
