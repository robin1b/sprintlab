<?php

namespace Tests\Feature\Sprint;

use App\Enums\Colleague;
use App\Enums\ScenarioAction;
use App\Enums\ScenarioTrigger;
use App\Enums\SprintStatus;
use App\Enums\TicketStatus;
use App\Models\Message;
use App\Models\ScenarioEvent;
use App\Models\Sprint;
use App\Models\Ticket;
use App\Services\Scenario\ScenarioEngine;
use App\Services\Sprint\SprintService;
use App\Services\Sprint\TicketService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SprintFixtures;
use Tests\TestCase;

class TicketServiceTest extends TestCase
{
    use RefreshDatabase, SprintFixtures;

    private TicketService $tickets;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tickets = app(TicketService::class);
    }

    public function test_a_ticket_can_move_to_any_status(): void
    {
        $sprint = $this->startDemoSprint();
        $ticket = $this->ticket($sprint, 'WK-1');

        foreach ([TicketStatus::InReview, TicketStatus::Todo, TicketStatus::Done, TicketStatus::InProgress] as $status) {
            $this->tickets->move($ticket, $status);
            $this->assertSame($status, $ticket->refresh()->status);
        }
    }

    public function test_each_trigger_fires_once_even_when_a_ticket_moves_back_and_forth(): void
    {
        $sprint = $this->startDemoSprint();
        $ticket = $this->ticket($sprint, 'WK-2');
        $criteria = count($ticket->acceptance_criteria);

        $this->tickets->move($ticket, TicketStatus::InReview);
        $this->tickets->move($ticket, TicketStatus::InProgress);
        $this->tickets->move($ticket, TicketStatus::InReview);
        $this->tickets->move($ticket, TicketStatus::Todo);
        $this->tickets->move($ticket, TicketStatus::InReview);

        $this->assertCount($criteria + 1, $ticket->refresh()->acceptance_criteria);
        $this->assertSame(1, Message::where('body', 'like', 'Sorry Robin%')->count());
        $this->assertSame(1, $sprint->sprintEvents()->whereNotNull('fired_at')->whereHas('scenarioEvent', fn ($q) => $q->where('key', 'change-wk-2'))->count());
    }

    public function test_moving_to_the_same_status_does_nothing(): void
    {
        $sprint = $this->startDemoSprint();
        $ticket = $this->ticket($sprint, 'WK-2');
        $events = $sprint->sprintEvents()->count();

        $this->tickets->move($ticket, TicketStatus::Todo);

        $this->assertSame($events, $sprint->sprintEvents()->count());
    }

    public function test_a_ticket_specific_event_only_fires_for_its_ticket(): void
    {
        $sprint = $this->startDemoSprint();

        $this->tickets->move($this->ticket($sprint, 'WK-1'), TicketStatus::InReview);

        $this->assertSame(0, Message::where('body', 'like', 'Sorry Robin%')->count());
        $this->assertCount(4, $this->ticket($sprint, 'WK-2')->acceptance_criteria);
    }

    public function test_the_requirement_change_adds_a_criterion_and_a_message_linked_to_the_ticket(): void
    {
        $sprint = $this->startDemoSprint();
        $ticket = $this->ticket($sprint, 'WK-2');

        $this->tickets->move($ticket, TicketStatus::InReview);

        $ticket->refresh();
        $this->assertCount(5, $ticket->acceptance_criteria);
        $this->assertSame('The filter can also be limited to devices that are for sale', $ticket->acceptance_criteria[4]);

        $message = Message::where('body', 'like', 'Sorry Robin%')->sole();
        $this->assertSame(Colleague::Daniel, $message->colleague);
        $this->assertSame($ticket->id, $message->ticket_id);
    }

    public function test_update_ticket_can_also_extend_the_description_and_replace_the_title(): void
    {
        $sprint = $this->startDemoSprint();
        $ticket = $this->ticket($sprint, 'WK-2');
        $original = $ticket->description;

        ScenarioEvent::where('key', 'change-wk-2')->sole()->update(['payload' => [
            'add_criteria' => ['Extra'],
            'description_append' => 'Sales wants this too.',
            'title' => 'Filter the catalogue by budget',
        ]]);

        $this->tickets->move($ticket, TicketStatus::InReview);

        $ticket->refresh();
        $this->assertSame($original."\n\nSales wants this too.", $ticket->description);
        $this->assertSame('Filter the catalogue by budget', $ticket->title);
        $this->assertContains('Extra', $ticket->acceptance_criteria);
        $this->assertSame(0, Message::where('body', 'like', 'Sorry%')->count());
    }

    public function test_release_ticket_releases_the_ticket_and_sends_the_message(): void
    {
        $sprint = $this->startDemoSprint();
        $ticket = $this->ticket($sprint, 'WK-3');
        $this->assertFalse($ticket->isReleased());

        $event = ScenarioEvent::create([
            'scenario_id' => $sprint->scenario_id,
            'key' => 'surprise',
            'trigger' => ScenarioTrigger::TicketStarted,
            'ticket_key' => 'WK-3',
            'action' => ScenarioAction::ReleaseTicket,
            'colleague' => Colleague::Sarah,
            'payload' => ['body' => 'This one just came in.'],
        ]);

        // A ticket trigger for another ticket does not match.
        $this->tickets->move($this->ticket($sprint, 'WK-1'), TicketStatus::InProgress);
        $this->assertFalse($ticket->refresh()->isReleased());

        app(ScenarioEngine::class)->dispatch($sprint, ScenarioTrigger::TicketStarted, $ticket);

        $this->assertTrue($ticket->refresh()->isReleased());
        $message = Message::where('body', 'This one just came in.')->sole();
        $this->assertSame($ticket->id, $message->ticket_id);
        $this->assertNotNull($sprint->sprintEvents()->where('scenario_event_id', $event->id)->sole()->fired_at);
    }

    public function test_a_release_event_on_a_sprint_trigger_releases_its_ticket(): void
    {
        $sprint = $this->startDemoSprint();

        ScenarioEvent::create([
            'scenario_id' => $sprint->scenario_id,
            'key' => 'late-ticket',
            'trigger' => ScenarioTrigger::DayStarted,
            'ticket_key' => 'WK-3',
            'day' => 1,
            'action' => ScenarioAction::ReleaseTicket,
            'payload' => [],
        ]);

        app(ScenarioEngine::class)->dispatch($sprint, ScenarioTrigger::DayStarted, day: 1);

        $this->assertTrue($this->ticket($sprint, 'WK-3')->isReleased());
    }

    public function test_an_event_for_another_day_does_not_fire(): void
    {
        $sprint = $this->startDemoSprint();
        $before = Message::count();

        app(ScenarioEngine::class)->dispatch($sprint, ScenarioTrigger::DayStarted, day: 3);

        $this->assertSame($before, Message::count());
    }

    public function test_a_delayed_event_waits_for_its_time_and_is_fired_by_run_due(): void
    {
        $sprint = $this->startDemoSprint();
        $engine = app(ScenarioEngine::class);

        ScenarioEvent::where('key', 'change-wk-2')->sole()->update(['delay_minutes' => 15]);

        $this->tickets->move($this->ticket($sprint, 'WK-2'), TicketStatus::InReview);

        $pending = $sprint->sprintEvents()->whereNull('fired_at')->sole();
        $this->assertSame(0, Message::where('body', 'like', 'Sorry%')->count());
        $this->assertSame(0, $engine->runDue());

        $this->travel(14)->minutes();
        $this->assertSame(0, $engine->runDue());

        $this->travel(2)->minutes();
        $this->assertSame(1, $engine->runDue());
        $this->assertSame(1, Message::where('body', 'like', 'Sorry%')->count());
        $this->assertNotNull($pending->refresh()->fired_at);
        $this->assertCount(5, $this->ticket($sprint, 'WK-2')->acceptance_criteria);

        // A second run finds nothing left.
        $this->assertSame(0, $engine->runDue());
        $this->assertSame(1, Message::where('body', 'like', 'Sorry%')->count());
    }

    public function test_run_due_without_a_sprint_covers_all_active_sprints(): void
    {
        $first = $this->startDemoSprint();
        $second = $this->startDemoSprint();
        ScenarioEvent::where('key', 'change-wk-2')->sole()->update(['delay_minutes' => 5]);

        $this->tickets->move($this->ticket($first, 'WK-2'), TicketStatus::InReview);
        $this->tickets->move($this->ticket($second, 'WK-2'), TicketStatus::InReview);

        $this->travel(6)->minutes();

        $this->assertSame(2, app(ScenarioEngine::class)->runDue());
    }

    public function test_finishing_all_day_one_tickets_starts_day_two(): void
    {
        $sprint = $this->startDemoSprint();

        $this->tickets->move($this->ticket($sprint, 'WK-1'), TicketStatus::Done);
        $sprint->refresh();
        $this->assertSame(1, $sprint->current_day);
        $this->assertFalse($this->ticket($sprint, 'WK-3')->isReleased());

        $this->tickets->move($this->ticket($sprint, 'WK-2'), TicketStatus::Done);

        $sprint->refresh();
        $this->assertSame(2, $sprint->current_day);
        $this->assertTrue($sprint->isActive());
        $this->assertTrue($this->ticket($sprint, 'WK-3')->isReleased());
        $this->assertSame(1, Message::where('body', 'like', 'Good morning Robin%')->count());
    }

    public function test_finishing_the_last_day_completes_the_sprint_and_sends_the_closing_message(): void
    {
        // The evaluation job is not under test here (and would add a message).
        Queue::fake();

        $sprint = $this->startDemoSprint();

        foreach (['WK-1', 'WK-2', 'WK-3'] as $key) {
            $this->tickets->move($this->ticket($sprint, $key), TicketStatus::Done);
        }

        $sprint->refresh();
        $this->assertSame(SprintStatus::Completed, $sprint->status);
        $this->assertNotNull($sprint->completed_at);

        // The WK-3 reaction came before the closing message.
        $bodies = $sprint->messages()->orderBy('id')->pluck('body');
        $this->assertStringStartsWith('WK-3 is merged', $bodies[$bodies->count() - 2]);
        $this->assertStringStartsWith("That's the sprint wrapped up, Robin", $bodies->last());

        // Nothing moves any more.
        $this->expectException(DomainException::class);
        $this->tickets->move($this->ticket($sprint, 'WK-3'), TicketStatus::InProgress);
    }

    public function test_a_delayed_closing_message_still_arrives_after_the_sprint_is_completed(): void
    {
        // The evaluation job is not under test here (and would add a message).
        Queue::fake();

        $sprint = $this->startDemoSprint();
        ScenarioEvent::where('key', 'sprint-closing')->sole()->update(['delay_minutes' => 30]);

        foreach (['WK-1', 'WK-2', 'WK-3'] as $key) {
            $this->tickets->move($this->ticket($sprint, $key), TicketStatus::Done);
        }

        $this->assertSame(0, Message::where('body', 'like', "That's the sprint%")->count());

        $this->travel(31)->minutes();
        $this->assertSame(1, app(ScenarioEngine::class)->runDue());
        $this->assertSame(1, Message::where('body', 'like', "That's the sprint%")->count());
    }

    public function test_a_paused_sprint_blocks_moving_and_firing(): void
    {
        $sprint = $this->startDemoSprint();
        $engine = app(ScenarioEngine::class);
        ScenarioEvent::where('key', 'change-wk-2')->sole()->update(['delay_minutes' => 5]);
        $this->tickets->move($this->ticket($sprint, 'WK-2'), TicketStatus::InReview);

        app(SprintService::class)->pause($sprint);

        try {
            $this->tickets->move($this->ticket($sprint, 'WK-1'), TicketStatus::InProgress);
            $this->fail('A paused sprint cannot move tickets.');
        } catch (DomainException) {
        }

        $this->assertSame(TicketStatus::Todo, $this->ticket($sprint, 'WK-1')->status);

        $this->travel(10)->minutes();
        $this->assertSame(0, $engine->runDue());
        $this->assertSame(0, $engine->runDue($sprint));
        $this->assertSame(0, Message::where('body', 'like', 'Sorry%')->count());

        // Ten minutes of pause do not count: the event is not due yet after resuming.
        app(SprintService::class)->resume($sprint);
        $this->assertSame(0, $engine->runDue());

        $this->travel(6)->minutes();
        $this->assertSame(1, $engine->runDue());
    }

    public function test_a_ticket_that_is_not_released_cannot_move(): void
    {
        $sprint = $this->startDemoSprint();

        $this->expectException(DomainException::class);

        $this->tickets->move($this->ticket($sprint, 'WK-3'), TicketStatus::InProgress);
    }

    public function test_a_completed_sprint_does_not_fire_other_events(): void
    {
        $sprint = Sprint::factory()->completed()->create();
        $event = ScenarioEvent::factory()->create(['scenario_id' => $sprint->scenario_id]);
        $sprint->sprintEvents()->create(['scenario_event_id' => $event->id, 'due_at' => now()->subMinute()]);

        $this->assertSame(0, app(ScenarioEngine::class)->runDue());
        $this->assertSame(0, Message::count());
    }

    public function test_the_factories_build_a_usable_ticket(): void
    {
        $ticket = Ticket::factory()->released()->status(TicketStatus::InReview)->create();

        $this->assertTrue($ticket->isReleased());
        $this->assertSame(TicketStatus::InReview, $ticket->status);
        $this->assertSame('In review', $ticket->status->label());
    }

    public function test_a_ticket_that_goes_straight_to_review_was_started_too(): void
    {
        $sprint = $this->startDemoSprint();
        $ticket = $this->ticket($sprint, 'WK-1');

        $event = ScenarioEvent::create([
            'scenario_id' => $sprint->scenario_id,
            'key' => 'tip-at-the-start',
            'trigger' => ScenarioTrigger::TicketStarted,
            'ticket_key' => 'WK-1',
            'action' => ScenarioAction::Message,
            'colleague' => Colleague::Emily,
            'payload' => ['body' => 'Shout if the setup fails.'],
        ]);

        // The user never dragged the card: opening the pull request is the first sign.
        $this->tickets->move($ticket, TicketStatus::InReview);

        $this->assertSame(1, Message::where('body', 'Shout if the setup fails.')->count());

        // Back to work and into review again: the start does not happen twice.
        $this->tickets->move($ticket->refresh(), TicketStatus::InProgress);
        $this->tickets->move($ticket->refresh(), TicketStatus::InReview);

        $this->assertSame(1, Message::where('body', 'Shout if the setup fails.')->count());
        $this->assertSame(1, $sprint->sprintEvents()->where('scenario_event_id', $event->id)->count());
    }
}
