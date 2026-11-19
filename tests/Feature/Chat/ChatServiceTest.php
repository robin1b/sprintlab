<?php

namespace Tests\Feature\Chat;

use App\Ai\Agents\ColleagueChat;
use App\Enums\Colleague;
use App\Enums\SprintStatus;
use App\Jobs\ReplyToChatMessage;
use App\Models\Message;
use App\Services\Chat\ChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use RuntimeException;
use Tests\Support\ChatFixtures;
use Tests\Support\SprintFixtures;
use Tests\TestCase;

class ChatServiceTest extends TestCase
{
    use ChatFixtures;
    use RefreshDatabase;
    use SprintFixtures;

    private function service(): ChatService
    {
        return app(ChatService::class);
    }

    public function test_send_stores_the_message_and_dispatches_the_job()
    {
        Queue::fake();
        $sprint = $this->startDemoSprint();

        $message = $this->service()->send($sprint, Colleague::Josh, 'Hi Josh');

        $this->assertTrue($message->from_user);
        $this->assertSame(Colleague::Josh, $message->colleague);
        Queue::assertPushed(ReplyToChatMessage::class, 1);
    }

    public function test_reply_stores_the_answer_with_model_and_tokens()
    {
        $sprint = $this->startDemoSprint();
        $message = $this->userMessage($sprint);
        $this->fakeColleagueWithUsage('Look at the approve method.', 120, 30);

        $reply = $this->service()->reply($message);

        $this->assertNotNull($reply);
        $this->assertFalse($reply->from_user);
        $this->assertSame(Colleague::Emily, $reply->colleague);
        $this->assertSame($sprint->id, $reply->sprint_id);
        $this->assertSame('Look at the approve method.', $reply->body);
        $this->assertSame(config('sprintlab.ai.chat_model'), $reply->model);
        $this->assertSame(120, $reply->input_tokens);
        $this->assertSame(30, $reply->output_tokens);
        $this->assertNotNull($message->fresh()->answered_at);
        ColleagueChat::assertPromptedTimes(1);
    }

    public function test_the_agent_gets_the_history_in_order_and_the_newest_message_as_prompt()
    {
        $sprint = $this->startDemoSprint();
        Message::factory()->create(['sprint_id' => $sprint->id, 'colleague' => Colleague::Emily, 'body' => 'Welcome']);
        Message::factory()->fromUser()->answered()->create(['sprint_id' => $sprint->id, 'colleague' => Colleague::Emily, 'body' => 'First question']);
        Message::factory()->aiReply()->create(['sprint_id' => $sprint->id, 'colleague' => Colleague::Emily, 'body' => 'First answer']);
        // Another conversation must stay out of it.
        Message::factory()->create(['sprint_id' => $sprint->id, 'colleague' => Colleague::Josh, 'body' => 'Josh talk']);
        $message = $this->userMessage($sprint, 'Second question');
        $this->fakeColleague();

        $this->service()->reply($message);

        ColleagueChat::assertPrompted(function (AgentPrompt $prompt) {
            $history = collect($prompt->agent->messages());

            // The scripted opening ("Welcome") is not part of the history:
            // the API wants the conversation to start with the user, so it
            // is passed along in the instructions instead.
            return $prompt->prompt === 'Second question'
                && $history->map(fn ($m) => [$m::class, $m->content])->all() === [
                    [UserMessage::class, 'First question'],
                    [AssistantMessage::class, 'First answer'],
                ]
                && str_contains((string) $prompt->agent->instructions(), '- Welcome');
        });
    }

    public function test_the_history_is_capped_at_the_configured_number()
    {
        config(['sprintlab.ai.chat_history_messages' => 3]);
        $sprint = $this->startDemoSprint();

        foreach (range(1, 6) as $number) {
            Message::factory()->answered()->create([
                'sprint_id' => $sprint->id, 'colleague' => Colleague::Emily, 'from_user' => $number % 2 === 0, 'body' => "Old {$number}",
            ]);
        }

        $message = $this->userMessage($sprint, 'New');
        $this->fakeColleague();

        $this->service()->reply($message);

        ColleagueChat::assertPrompted(fn (AgentPrompt $prompt) => collect($prompt->agent->messages())->pluck('content')->all() === ['Old 4', 'Old 5', 'Old 6']);
    }

    public function test_several_unanswered_messages_get_one_reply()
    {
        $sprint = $this->startDemoSprint();
        $first = $this->userMessage($sprint, 'One');
        $second = $this->userMessage($sprint, 'Two');
        $this->fakeColleague();

        $this->service()->reply($first);
        // The second job finds nothing left to do.
        $this->assertNull($this->service()->reply($second));

        $this->assertSame(1, $sprint->messages()->where('from_user', false)->whereNotNull('model')->count());
        $this->assertNotNull($first->fresh()->answered_at);
        $this->assertNotNull($second->fresh()->answered_at);
        ColleagueChat::assertPromptedTimes(1);
        ColleagueChat::assertPrompted(fn (AgentPrompt $prompt) => $prompt->prompt === "One\n\nTwo");
    }

    public function test_a_message_that_is_not_from_the_user_or_already_answered_is_left_alone()
    {
        $sprint = $this->startDemoSprint();
        $fromColleague = Message::factory()->create(['sprint_id' => $sprint->id]);
        $answered = Message::factory()->fromUser()->answered()->create(['sprint_id' => $sprint->id]);
        $this->fakeColleague();

        $this->assertNull($this->service()->reply($fromColleague));
        $this->assertNull($this->service()->reply($answered));

        ColleagueChat::assertNeverPrompted();
    }

    public function test_the_daily_limit_gives_a_scripted_reply_without_calling_the_ai()
    {
        config(['sprintlab.ai.chat_replies_per_sprint_per_day' => 2]);
        $sprint = $this->startDemoSprint();
        Message::factory()->count(2)->aiReply()->create(['sprint_id' => $sprint->id, 'colleague' => Colleague::Josh]);
        $message = $this->userMessage($sprint);
        $this->fakeColleague();

        $reply = $this->service()->reply($message);

        $this->assertSame("I'm in meetings for the rest of the day. Let's pick this up tomorrow.", $reply->body);
        $this->assertSame(Colleague::Emily, $reply->colleague);
        $this->assertNull($reply->model);
        $this->assertNotNull($message->fresh()->answered_at);
        ColleagueChat::assertNeverPrompted();
    }

    public function test_replies_of_yesterday_and_scripted_messages_do_not_count_for_the_limit()
    {
        config(['sprintlab.ai.chat_replies_per_sprint_per_day' => 1]);
        $sprint = $this->startDemoSprint();
        Message::factory()->aiReply()->create(['sprint_id' => $sprint->id, 'created_at' => now()->subDay()]);
        Message::factory()->create(['sprint_id' => $sprint->id]);
        $message = $this->userMessage($sprint);
        $this->fakeColleague();

        $this->service()->reply($message);

        ColleagueChat::assertPromptedTimes(1);
    }

    public function test_with_ai_switched_off_the_message_is_answered_without_a_reply()
    {
        config(['sprintlab.ai.enabled' => false]);
        $sprint = $this->startDemoSprint();
        $message = $this->userMessage($sprint);
        $before = Message::count();
        $this->fakeColleague();

        $this->assertNull($this->service()->reply($message));

        $this->assertNotNull($message->fresh()->answered_at);
        $this->assertSame($before, Message::count());
        ColleagueChat::assertNeverPrompted();
    }

    public function test_a_paused_sprint_leaves_the_message_unanswered()
    {
        $sprint = $this->setSprintStatus($this->startDemoSprint(), SprintStatus::Paused);
        $message = $this->userMessage($sprint);
        $before = Message::count();
        $this->fakeColleague();

        $this->assertNull($this->service()->reply($message));

        $this->assertNull($message->fresh()->answered_at);
        $this->assertSame($before, Message::count());
        ColleagueChat::assertNeverPrompted();

        // After resuming it is answered.
        $this->setSprintStatus($sprint, SprintStatus::Active);
        $this->assertNotNull($this->service()->reply($message));
    }

    public function test_a_completed_sprint_marks_the_message_answered_without_a_reply()
    {
        $sprint = $this->setSprintStatus($this->startDemoSprint(), SprintStatus::Completed);
        $message = $this->userMessage($sprint);
        $before = Message::count();
        $this->fakeColleague();

        $this->assertNull($this->service()->reply($message));

        $this->assertNotNull($message->fresh()->answered_at);
        $this->assertSame($before, Message::count());
        ColleagueChat::assertNeverPrompted();
    }

    public function test_an_exception_from_the_ai_is_not_caught_and_leaves_the_message_unanswered()
    {
        $sprint = $this->startDemoSprint();
        $message = $this->userMessage($sprint);
        ColleagueChat::fake(fn () => throw new RuntimeException('API down'));

        try {
            $this->service()->reply($message);
            $this->fail('The exception should reach the job.');
        } catch (RuntimeException $exception) {
            $this->assertSame('API down', $exception->getMessage());
        }

        $this->assertNull($message->fresh()->answered_at);
        $this->assertSame(0, $sprint->messages()->where('from_user', false)->whereNotNull('model')->count());
    }

    public function test_the_failed_hook_stores_the_fallback_reply()
    {
        $sprint = $this->startDemoSprint();
        $first = $this->userMessage($sprint, 'One');
        $second = $this->userMessage($sprint, 'Two');

        (new ReplyToChatMessage($first->id))->failed(new RuntimeException('API down'));

        $reply = $sprint->messages()->where('from_user', false)->latest('id')->first();
        $this->assertSame('Sorry, I got pulled into something. Can you ask me again in a bit?', $reply->body);
        $this->assertSame(Colleague::Emily, $reply->colleague);
        $this->assertNull($reply->model);
        $this->assertNotNull($first->fresh()->answered_at);
        $this->assertNotNull($second->fresh()->answered_at);
    }

    public function test_the_job_answers_through_the_service()
    {
        $sprint = $this->startDemoSprint();
        $message = $this->userMessage($sprint);
        $this->fakeColleague(['On it.']);

        (new ReplyToChatMessage($message->id))->handle($this->service());

        $this->assertSame('On it.', $sprint->messages()->where('from_user', false)->latest('id')->first()->body);
    }

    public function test_a_deleted_message_is_ignored_by_the_job()
    {
        $this->fakeColleague();

        (new ReplyToChatMessage(999))->handle($this->service());

        ColleagueChat::assertNeverPrompted();
    }
}
