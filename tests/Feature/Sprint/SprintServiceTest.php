<?php

namespace Tests\Feature\Sprint;

use App\Enums\Colleague;
use App\Enums\ScenarioTrigger;
use App\Enums\SprintStatus;
use App\Enums\TicketStatus;
use App\Models\Message;
use App\Models\Sprint;
use App\Models\User;
use App\Services\Scenario\ScenarioEngine;
use App\Services\Sprint\SprintService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SprintFixtures;
use Tests\TestCase;

class SprintServiceTest extends TestCase
{
    use RefreshDatabase, SprintFixtures;

    public function test_starting_a_sprint_copies_the_tickets_and_releases_day_one(): void
    {
        $this->travelTo(now()->startOfSecond());

        $sprint = $this->startDemoSprint();

        $this->assertSame(SprintStatus::Active, $sprint->status);
        $this->assertSame(1, $sprint->current_day);
        $this->assertSame(now()->addDays(7)->getTimestamp(), $sprint->deadline_at->getTimestamp());
        $this->assertSame(['WK-1', 'WK-2', 'WK-3'], $sprint->tickets()->orderBy('sort')->pluck('key')->all());

        $this->assertTrue($this->ticket($sprint, 'WK-1')->isReleased());
        $this->assertTrue($this->ticket($sprint, 'WK-2')->isReleased());
        $this->assertFalse($this->ticket($sprint, 'WK-3')->isReleased());
        $this->assertSame(TicketStatus::Todo, $this->ticket($sprint, 'WK-1')->status);
    }

    public function test_the_welcome_message_and_the_day_one_introduction_arrive_with_the_first_name(): void
    {
        $sprint = $this->startDemoSprint();

        $messages = $sprint->messages()->orderBy('id')->get();

        $this->assertCount(2, $messages);
        $this->assertSame(Colleague::Sarah, $messages[0]->colleague);
        $this->assertStringStartsWith('Hi Robin, welcome', $messages[0]->body);
        $this->assertFalse($messages[0]->from_user);
        $this->assertSame(Colleague::Daniel, $messages[1]->colleague);
        $this->assertStringContainsString('Hey Robin', $messages[1]->body);
        $this->assertStringNotContainsString('{', $messages[0]->body.$messages[1]->body);
    }

    public function test_a_ticket_copy_keeps_its_own_criteria(): void
    {
        $sprint = $this->startDemoSprint();

        $this->assertCount(3, $this->ticket($sprint, 'WK-1')->acceptance_criteria);
        $this->assertSame($this->ticket($sprint, 'WK-1')->scenarioTicket->acceptance_criteria, $this->ticket($sprint, 'WK-1')->acceptance_criteria);
    }

    public function test_a_second_unfinished_sprint_is_refused(): void
    {
        $user = User::factory()->create();
        $this->startDemoSprint($user);

        $this->expectException(DomainException::class);

        app(SprintService::class)->start($user, $this->demoScenario());
    }

    public function test_a_paused_sprint_also_blocks_a_new_one(): void
    {
        $user = User::factory()->create();
        $sprint = $this->startDemoSprint($user);
        app(SprintService::class)->pause($sprint);

        $this->expectException(DomainException::class);

        app(SprintService::class)->start($user, $this->demoScenario());
    }

    public function test_a_new_sprint_is_possible_after_the_last_one_is_completed(): void
    {
        $user = User::factory()->create();
        $first = $this->startDemoSprint($user);
        $first->update(['status' => SprintStatus::Completed, 'completed_at' => now()]);

        $second = app(SprintService::class)->start($user, $this->demoScenario());

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(2, $user->sprints()->count());
    }

    public function test_a_refused_start_leaves_nothing_behind(): void
    {
        $user = User::factory()->create();
        $this->startDemoSprint($user);

        try {
            app(SprintService::class)->start($user, $this->demoScenario());
        } catch (DomainException) {
        }

        $this->assertSame(1, Sprint::count());
    }

    public function test_current_for_returns_the_newest_unfinished_sprint(): void
    {
        $service = app(SprintService::class);
        $user = User::factory()->create();

        $this->assertNull($service->currentFor($user));

        $sprint = $this->startDemoSprint($user);
        $this->assertTrue($service->currentFor($user)?->is($sprint));

        $sprint->update(['status' => SprintStatus::Completed]);
        $this->assertNull($service->currentFor($user));
    }

    public function test_pausing_and_resuming_only_work_in_the_right_state(): void
    {
        $service = app(SprintService::class);
        $sprint = $this->startDemoSprint();

        try {
            $service->resume($sprint);
            $this->fail('An active sprint cannot be resumed.');
        } catch (DomainException) {
        }

        $service->pause($sprint);
        $this->assertTrue($sprint->isPaused());
        $this->assertNotNull($sprint->paused_at);

        try {
            $service->pause($sprint);
            $this->fail('A paused sprint cannot be paused again.');
        } catch (DomainException) {
        }

        $service->resume($sprint);
        $this->assertTrue($sprint->isActive());
        $this->assertNull($sprint->paused_at);
    }

    public function test_resuming_moves_the_deadline_and_the_pending_events_by_the_pause(): void
    {
        $this->travelTo(now()->startOfSecond());
        $service = app(SprintService::class);
        $sprint = $this->startDemoSprint();
        $deadline = $sprint->deadline_at;

        // A scheduled event, due in 10 minutes.
        $event = $sprint->scenario->scenarioEvents()->where('key', 'sprint-closing')->sole();
        $pending = $sprint->sprintEvents()->create(['scenario_event_id' => $event->id, 'due_at' => now()->addMinutes(10)]);

        $service->pause($sprint);
        $this->travel(3)->hours();
        $service->resume($sprint);

        $this->assertSame($deadline->addHours(3)->getTimestamp(), $sprint->deadline_at->getTimestamp());
        $this->assertSame(now()->addMinutes(10)->getTimestamp(), $pending->refresh()->due_at->getTimestamp());
        $this->assertSame(0, app(ScenarioEngine::class)->runDue($sprint));
    }

    public function test_an_overdue_sprint_is_an_active_sprint_past_its_deadline(): void
    {
        $sprint = Sprint::factory()->create(['deadline_at' => now()->subMinute()]);
        $this->assertTrue($sprint->isOverdue());

        $this->assertFalse(Sprint::factory()->create(['deadline_at' => now()->addDay()])->isOverdue());
        $this->assertFalse(Sprint::factory()->paused()->create(['deadline_at' => now()->subMinute()])->isOverdue());
        $this->assertFalse(Sprint::factory()->completed()->create(['deadline_at' => now()->subMinute()])->isOverdue());
    }

    public function test_the_welcome_is_not_sent_twice_for_one_sprint(): void
    {
        $sprint = $this->startDemoSprint();

        app(ScenarioEngine::class)->dispatch($sprint, ScenarioTrigger::SprintStarted);

        $this->assertSame(1, Message::where('body', 'like', 'Hi Robin%')->count());
    }
}
