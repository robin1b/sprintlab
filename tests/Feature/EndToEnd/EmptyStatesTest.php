<?php

namespace Tests\Feature\EndToEnd;

use App\Enums\SprintStatus;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ChatFixtures;
use Tests\Support\SprintFixtures;
use Tests\TestCase;

/**
 * The props the empty states of the pages depend on: no sprint, a paused
 * sprint, a conversation without messages and a sprint that is not finished.
 */
class EmptyStatesTest extends TestCase
{
    use ChatFixtures, RefreshDatabase, SprintFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_the_board_without_a_sprint_has_no_sprint_and_no_tickets(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('board'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Board')
                ->where('sprint', null)
                ->has('tickets', 0)
                ->has('statuses', 4));
    }

    public function test_the_board_of_a_paused_sprint_says_so_through_the_shared_sprint(): void
    {
        $sprint = $this->setSprintStatus($this->startDemoSprint(), SprintStatus::Paused);

        $this->actingAs($sprint->user)
            ->get(route('board'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Board')
                ->where('sprint.status', 'paused')
                ->has('tickets', 3));
    }

    public function test_the_chat_without_a_sprint_cannot_send_and_gives_the_reason(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('chat', 'emily'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Chat')
                ->where('sprint', null)
                ->where('selected', 'emily')
                ->has('messages', 0)
                ->where('can_send', false)
                ->where('send_blocked_reason', 'Start a sprint to chat with your team.'));
    }

    public function test_a_conversation_without_messages_is_empty_but_open_for_writing(): void
    {
        $sprint = $this->startDemoSprint();

        $this->actingAs($sprint->user)
            ->get(route('chat', 'emily'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Chat')
                ->where('selected', 'emily')
                ->has('messages', 0)
                ->where('can_send', true)
                ->where('send_blocked_reason', null));
    }

    public function test_a_conversation_without_messages_in_a_paused_sprint_says_why_nobody_can_write(): void
    {
        $sprint = $this->setSprintStatus($this->startDemoSprint(), SprintStatus::Paused);

        $this->actingAs($sprint->user)
            ->get(route('chat', 'josh'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Chat')
                ->has('messages', 0)
                ->where('can_send', false)
                ->where('send_blocked_reason', 'Your sprint is paused. Resume it to chat.'));
    }

    public function test_the_evaluation_of_a_paused_sprint_is_not_finished(): void
    {
        $sprint = $this->setSprintStatus($this->startDemoSprint(), SprintStatus::Paused);

        $this->actingAs($sprint->user)
            ->get(route('sprints.evaluation.show', $sprint))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sprints/Evaluation')
                ->where('finished', false)
                ->where('evaluation', null));
    }

    public function test_the_dashboard_without_a_sprint_offers_the_demo_and_has_no_overview(): void
    {
        $this->demoScenario();

        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('overview', null)
                ->where('completed', null)
                ->has('scenarios', 1));
    }
}
