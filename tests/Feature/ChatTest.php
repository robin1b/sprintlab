<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\SprintFixtures;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;
    use SprintFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get(route('chat'))->assertRedirect(route('login'));
    }

    public function test_without_a_sprint_the_chat_is_empty()
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('chat'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Chat')
                ->has('colleagues', 5)
                ->where('selected', 'sarah')
                ->has('messages', 0));
    }

    public function test_the_newest_conversation_is_selected_by_default()
    {
        $sprint = $this->startDemoSprint();
        $this->actingAs($sprint->user);

        // Sarah's welcome comes first, Daniel's introduction after it.
        $this->get(route('chat'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected', 'daniel')
                ->has('messages', 1)
                ->where('messages.0.from_user', false)
                ->where('messages.0.body', fn (string $body) => str_contains($body, 'Daniel here'))
                ->where('colleagues.0.key', 'sarah')
                ->where('colleagues.0.unread', 1)
                ->where('colleagues.0.last_message', fn (string $text) => $text !== '' && mb_strlen($text) <= 63)
                ->where('colleagues.1.unread', 0)
                ->where('colleagues.2.last_message', null));
    }

    public function test_opening_a_conversation_marks_its_messages_as_read()
    {
        $sprint = $this->startDemoSprint();
        $this->actingAs($sprint->user);
        $this->assertSame(2, $sprint->messages()->whereNull('read_at')->count());

        $this->get(route('chat', 'sarah'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('selected', 'sarah')
                ->where('colleagues.0.unread', 0)
                ->where('colleagues.1.unread', 1));

        $this->assertSame(1, $sprint->messages()->whereNull('read_at')->count());
        $this->assertSame('daniel', $sprint->messages()->whereNull('read_at')->first()->colleague->value);
    }

    public function test_messages_are_oldest_first_and_carry_the_ticket_key()
    {
        $sprint = $this->startDemoSprint();
        $ticket = $this->ticket($sprint, 'WK-1');
        Message::factory()->create(['sprint_id' => $sprint->id, 'body' => 'Second', 'ticket_id' => $ticket->id]);
        Message::factory()->create(['sprint_id' => $sprint->id, 'body' => 'Mine', 'from_user' => true]);
        $this->actingAs($sprint->user);

        $this->get(route('chat', 'sarah'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('messages', 3)
                ->where('messages.0.ticket_key', null)
                ->where('messages.1.body', 'Second')
                ->where('messages.1.ticket_key', 'WK-1')
                ->where('messages.2.from_user', true));
    }

    public function test_own_messages_do_not_count_as_unread()
    {
        $sprint = $this->startDemoSprint();
        Message::factory()->create(['sprint_id' => $sprint->id, 'from_user' => true]);
        $this->actingAs($sprint->user);

        $this->get(route('chat', 'josh'))
            ->assertInertia(fn (Assert $page) => $page->where('unreadMessages', 2));
    }

    public function test_an_unknown_colleague_is_a_404()
    {
        $this->actingAs(User::factory()->create());

        $this->get('/chat/nobody')->assertNotFound();
    }
}
