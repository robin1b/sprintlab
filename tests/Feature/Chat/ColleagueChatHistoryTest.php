<?php

namespace Tests\Feature\Chat;

use App\Ai\Agents\ColleagueChat;
use App\Enums\Colleague;
use App\Jobs\ReplyToChatMessage;
use App\Models\Message;
use App\Models\Sprint;
use App\Services\Sprint\SprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Tests\TestCase;

class ColleagueChatHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function message(Sprint $sprint, Colleague $colleague, bool $fromUser, string $body, bool $answered = true): Message
    {
        return Message::factory()->create([
            'sprint_id' => $sprint->id,
            'colleague' => $colleague,
            'from_user' => $fromUser,
            'body' => $body,
            'answered_at' => $fromUser && $answered ? now() : null,
        ]);
    }

    public function test_the_history_starts_with_the_user_and_the_scripted_opening_moves_to_the_instructions(): void
    {
        $sprint = Sprint::factory()->create();

        // A chat usually starts with scripted messages from the colleague.
        $this->message($sprint, Colleague::Daniel, false, 'Two tickets for you today.');
        $this->message($sprint, Colleague::Daniel, false, 'Small change on WK-2.');
        $this->message($sprint, Colleague::Daniel, true, 'Is the filter on price or on budget?');
        $this->message($sprint, Colleague::Daniel, false, 'On the monthly price.');
        $this->message($sprint, Colleague::Daniel, true, 'And for devices that are for sale?', answered: false);

        $agent = new ColleagueChat($sprint, Colleague::Daniel);
        $history = [...$agent->messages()];

        $this->assertCount(2, $history);
        $this->assertInstanceOf(UserMessage::class, $history[0]);
        $this->assertInstanceOf(AssistantMessage::class, $history[1]);
        $this->assertSame('And for devices that are for sale?', $agent->promptText());

        $instructions = $agent->instructions();

        $this->assertStringContainsString('Messages you already sent in this chat, oldest first:', $instructions);
        $this->assertStringContainsString('- Two tickets for you today.', $instructions);
        $this->assertStringContainsString('- Small change on WK-2.', $instructions);
        $this->assertStringNotContainsString('On the monthly price.', $instructions);
    }

    public function test_a_chat_the_user_opened_has_no_opening_note(): void
    {
        $sprint = Sprint::factory()->create();

        $this->message($sprint, Colleague::Emily, true, 'Where does the approval happen?', answered: false);

        $agent = new ColleagueChat($sprint, Colleague::Emily);

        $this->assertSame([], [...$agent->messages()]);
        $this->assertStringNotContainsString('Messages you already sent', $agent->instructions());
    }

    public function test_resuming_a_sprint_asks_for_the_replies_that_were_left_waiting(): void
    {
        Queue::fake();

        $sprint = Sprint::factory()->paused()->create();

        $toDaniel = $this->message($sprint, Colleague::Daniel, true, 'Quick question.', answered: false);
        $this->message($sprint, Colleague::Daniel, true, 'One more thing.', answered: false);
        $toSarah = $this->message($sprint, Colleague::Sarah, true, 'Can you look at my pull request?', answered: false);
        $this->message($sprint, Colleague::Emily, true, 'Thanks!');

        app(SprintService::class)->resume($sprint);

        // One job per conversation that still waits for an answer.
        Queue::assertPushed(ReplyToChatMessage::class, 2);
        Queue::assertPushed(ReplyToChatMessage::class, fn (ReplyToChatMessage $job) => $job->messageId === $toDaniel->id);
        Queue::assertPushed(ReplyToChatMessage::class, fn (ReplyToChatMessage $job) => $job->messageId === $toSarah->id);
    }
}
