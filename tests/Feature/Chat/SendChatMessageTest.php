<?php

namespace Tests\Feature\Chat;

use App\Enums\SprintStatus;
use App\Jobs\ReplyToChatMessage;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ChatFixtures;
use Tests\Support\SprintFixtures;
use Tests\TestCase;

class SendChatMessageTest extends TestCase
{
    use ChatFixtures;
    use RefreshDatabase;
    use SprintFixtures;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->post(route('chat.messages.store', 'emily'), ['body' => 'Hi'])->assertRedirect(route('login'));
    }

    public function test_sending_stores_the_message_and_queues_the_reply()
    {
        Queue::fake();
        $sprint = $this->startDemoSprint();

        $this->actingAs($sprint->user)
            ->from(route('chat', 'emily'))
            ->post(route('chat.messages.store', 'emily'), ['body' => '  Where do I start?  '])
            ->assertRedirect(route('chat', 'emily'))
            ->assertSessionHasNoErrors();

        $message = Message::where('from_user', true)->sole();
        $this->assertSame('Where do I start?', $message->body);
        $this->assertSame('emily', $message->colleague->value);
        $this->assertSame($sprint->id, $message->sprint_id);
        $this->assertNull($message->answered_at);

        Queue::assertPushed(ReplyToChatMessage::class, fn (ReplyToChatMessage $job) => $job->messageId === $message->id);
    }

    public function test_the_message_is_required()
    {
        Queue::fake();
        $sprint = $this->startDemoSprint();

        $this->actingAs($sprint->user)
            ->post(route('chat.messages.store', 'emily'), ['body' => ''])
            ->assertSessionHasErrors('body');
        $this->actingAs($sprint->user)
            ->post(route('chat.messages.store', 'emily'), ['body' => "   \n "])
            ->assertSessionHasErrors('body');

        $this->assertSame(0, Message::where('from_user', true)->count());
        Queue::assertNothingPushed();
    }

    public function test_the_message_has_a_maximum_length()
    {
        Queue::fake();
        $sprint = $this->startDemoSprint();
        $max = config('sprintlab.ai.chat_max_characters');

        $this->actingAs($sprint->user)
            ->post(route('chat.messages.store', 'emily'), ['body' => str_repeat('a', $max + 1)])
            ->assertSessionHasErrors('body');
        $this->actingAs($sprint->user)
            ->post(route('chat.messages.store', 'emily'), ['body' => str_repeat('a', $max)])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Message::where('from_user', true)->count());
    }

    public function test_without_a_sprint_nothing_is_stored()
    {
        Queue::fake();

        $this->actingAs(User::factory()->create())
            ->post(route('chat.messages.store', 'emily'), ['body' => 'Hi'])
            ->assertSessionHas('error', 'Start a sprint to chat with your team.');

        $this->assertSame(0, Message::count());
        Queue::assertNothingPushed();
    }

    public function test_a_paused_sprint_refuses_the_message()
    {
        Queue::fake();
        $sprint = $this->setSprintStatus($this->startDemoSprint(), SprintStatus::Paused);
        $before = Message::count();

        $this->actingAs($sprint->user)
            ->post(route('chat.messages.store', 'emily'), ['body' => 'Hi'])
            ->assertSessionHas('error', 'Your sprint is paused. Resume it to chat.');

        $this->assertSame($before, Message::count());
        Queue::assertNothingPushed();
    }

    public function test_a_message_always_lands_in_the_sprint_of_the_sender()
    {
        Queue::fake();
        $other = $this->startDemoSprint();
        $mine = $this->startDemoSprint(User::factory()->create());

        $this->actingAs($mine->user)
            ->post(route('chat.messages.store', 'emily'), ['body' => 'Hi']);

        $this->assertSame(1, $mine->messages()->where('from_user', true)->count());
        $this->assertSame(0, $other->messages()->where('from_user', true)->count());
    }

    public function test_an_unknown_colleague_is_a_404()
    {
        $sprint = $this->startDemoSprint();

        $this->actingAs($sprint->user)
            ->post('/chat/nobody/messages', ['body' => 'Hi'])
            ->assertNotFound();
    }

    public function test_the_job_retries_once_and_has_a_timeout()
    {
        $job = new ReplyToChatMessage(1);

        $this->assertSame(2, $job->tries);
        $this->assertSame(90, $job->timeout);
    }
}
