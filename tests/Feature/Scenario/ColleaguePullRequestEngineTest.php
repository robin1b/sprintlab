<?php

namespace Tests\Feature\Scenario;

use App\Enums\ScenarioTrigger;
use App\Jobs\OpenColleaguePullRequest;
use App\Models\SprintEvent;
use App\Services\Scenario\ScenarioEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Support\ColleaguePullRequestFixtures;
use Tests\Support\GitHubFixtures;
use Tests\TestCase;

class ColleaguePullRequestEngineTest extends TestCase
{
    use ColleaguePullRequestFixtures, GitHubFixtures, RefreshDatabase;

    public function test_the_event_releases_the_ticket_dispatches_the_job_and_sends_the_message(): void
    {
        Bus::fake();
        ['sprint' => $sprint, 'ticket' => $ticket, 'event' => $event] = $this->createPullRequestEvent();
        $this->assertFalse($ticket->isReleased());

        app(ScenarioEngine::class)->dispatch($sprint, ScenarioTrigger::DayStarted, day: 1);

        Bus::assertDispatched(OpenColleaguePullRequest::class, fn (OpenColleaguePullRequest $job): bool => $job->ticketId === $ticket->id && $job->scenarioEventId === $event->id);
        $this->assertTrue($ticket->fresh()->isReleased());

        $message = $sprint->messages()->sole();
        $this->assertSame('Can you review my pull request, Robin?', $message->body);
        $this->assertSame($ticket->id, $message->ticket_id);
    }

    public function test_the_file_contents_never_reach_the_chat(): void
    {
        Bus::fake();
        ['sprint' => $sprint] = $this->createPullRequestEvent();

        app(ScenarioEngine::class)->dispatch($sprint, ScenarioTrigger::DayStarted, day: 1);

        $this->assertStringNotContainsString('planted problem', $sprint->messages()->pluck('body')->implode(' '));
    }

    public function test_without_a_body_no_message_is_sent(): void
    {
        Bus::fake();
        ['sprint' => $sprint] = $this->createPullRequestEvent(['body' => null]);

        app(ScenarioEngine::class)->dispatch($sprint, ScenarioTrigger::DayStarted, day: 1);

        Bus::assertDispatched(OpenColleaguePullRequest::class);
        $this->assertSame(0, $sprint->messages()->count());
    }

    public function test_the_event_fires_once(): void
    {
        Bus::fake();
        ['sprint' => $sprint] = $this->createPullRequestEvent();

        app(ScenarioEngine::class)->dispatch($sprint, ScenarioTrigger::DayStarted, day: 1);
        app(ScenarioEngine::class)->dispatch($sprint, ScenarioTrigger::DayStarted, day: 1);

        Bus::assertDispatchedTimes(OpenColleaguePullRequest::class, 1);
        $this->assertSame(1, SprintEvent::count());
    }
}
