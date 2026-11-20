<?php

namespace Tests\Feature\Scenario;

use App\Enums\ScenarioAction;
use App\Enums\ScenarioTrigger;
use App\Models\Message;
use App\Models\ScenarioEvent;
use App\Models\Sprint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SprintFixtures;
use Tests\TestCase;

class RehearseScenarioTest extends TestCase
{
    use RefreshDatabase, SprintFixtures;

    public function test_the_demo_scenario_plays_from_start_to_finish(): void
    {
        $this->demoScenario();

        $this->artisan('sprintlab:rehearse demo-sprint')
            ->expectsOutputToContain('The sprint starts.')
            ->expectsOutputToContain('event change-wk-2: update_ticket (WK-2)')
            ->expectsOutputToContain('WK-3 moves to done.')
            ->expectsOutputToContain('The script holds')
            ->assertSuccessful();
    }

    public function test_a_rehearsal_leaves_nothing_behind(): void
    {
        $this->demoScenario();

        $this->artisan('sprintlab:rehearse demo-sprint')->assertSuccessful();

        $this->assertSame(0, User::count());
        $this->assertSame(0, Sprint::count());
        $this->assertSame(0, Message::count());
    }

    public function test_an_event_that_can_never_fire_is_reported(): void
    {
        $scenario = $this->demoScenario();

        // Waits for a ticket that is held back and that nothing releases.
        $scenario->scenarioTickets()->where('key', 'WK-3')->update(['released_at_start' => false]);

        ScenarioEvent::create([
            'scenario_id' => $scenario->id,
            'key' => 'thanks-for-wk-3',
            'trigger' => ScenarioTrigger::TicketDone,
            'ticket_key' => 'WK-3',
            'action' => ScenarioAction::Message,
            'colleague' => 'sarah',
            'payload' => ['body' => 'Thanks.'],
            'sort' => 99,
        ]);

        $this->artisan('sprintlab:rehearse demo-sprint')
            ->expectsOutputToContain('Ticket WK-3 never came on the board.')
            ->expectsOutputToContain('Event thanks-for-wk-3 never fired.')
            ->assertFailed();
    }

    public function test_an_unknown_scenario_is_refused(): void
    {
        $this->artisan('sprintlab:rehearse nothing-here')
            ->expectsOutputToContain('No imported scenario with that slug')
            ->assertFailed();
    }
}
