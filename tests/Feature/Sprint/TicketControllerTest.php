<?php

namespace Tests\Feature\Sprint;

use App\Enums\TicketStatus;
use App\Models\Sprint;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SprintFixtures;
use Tests\TestCase;

class TicketControllerTest extends TestCase
{
    use RefreshDatabase;
    use SprintFixtures;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $ticket = Ticket::factory()->released()->create();

        $this->patch(route('tickets.update', $ticket), ['status' => 'done'])->assertRedirect(route('login'));
    }

    public function test_a_ticket_can_be_moved()
    {
        $sprint = $this->startDemoSprint();
        $ticket = $this->ticket($sprint, 'WK-1');

        $this->actingAs($sprint->user)
            ->patch(route('tickets.update', $ticket), ['status' => 'in_progress'])
            ->assertRedirect()
            ->assertSessionMissing('error');

        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
    }

    public function test_moving_a_ticket_can_trigger_the_scenario()
    {
        $sprint = $this->startDemoSprint();
        $ticket = $this->ticket($sprint, 'WK-2');

        $this->actingAs($sprint->user)->patch(route('tickets.update', $ticket), ['status' => 'in_review']);

        $this->assertContains(
            'The filter can also be limited to devices that are for sale',
            $ticket->fresh()->acceptance_criteria,
        );
    }

    public function test_an_invalid_status_is_a_validation_error()
    {
        $sprint = $this->startDemoSprint();
        $ticket = $this->ticket($sprint, 'WK-1');

        $this->actingAs($sprint->user)
            ->patch(route('tickets.update', $ticket), ['status' => 'archived'])
            ->assertSessionHasErrors('status');

        $this->actingAs($sprint->user)
            ->patch(route('tickets.update', $ticket))
            ->assertSessionHasErrors('status');

        $this->assertSame(TicketStatus::Todo, $ticket->fresh()->status);
    }

    public function test_moving_while_paused_is_a_flash_error_and_leaves_the_ticket_alone()
    {
        $sprint = $this->startDemoSprint();
        $ticket = $this->ticket($sprint, 'WK-1');
        $sprint->update(['status' => 'paused', 'paused_at' => now()]);

        $this->actingAs($sprint->user)
            ->from(route('board'))
            ->patch(route('tickets.update', $ticket), ['status' => 'done'])
            ->assertRedirect(route('board'))
            ->assertSessionHas('error', 'This sprint is not active.');

        $this->assertSame(TicketStatus::Todo, $ticket->fresh()->status);
    }

    public function test_a_ticket_that_is_not_released_cannot_be_moved()
    {
        $sprint = $this->startDemoSprint();
        $ticket = $this->ticket($sprint, 'WK-3');

        $this->actingAs($sprint->user)
            ->patch(route('tickets.update', $ticket), ['status' => 'in_progress'])
            ->assertSessionHas('error', 'This ticket has not been released yet.');

        $this->assertSame(TicketStatus::Todo, $ticket->fresh()->status);
    }

    public function test_someone_elses_ticket_cannot_be_moved()
    {
        $sprint = $this->startDemoSprint();
        $ticket = $this->ticket($sprint, 'WK-1');

        $this->actingAs(User::factory()->create())
            ->patch(route('tickets.update', $ticket), ['status' => 'in_progress'])
            ->assertForbidden();

        $this->assertSame(TicketStatus::Todo, $ticket->fresh()->status);
        $this->assertInstanceOf(Sprint::class, $sprint);
    }
}
