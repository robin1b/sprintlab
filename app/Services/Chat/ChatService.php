<?php

namespace App\Services\Chat;

use App\Ai\Agents\ColleagueChat;
use App\Enums\Colleague;
use App\Jobs\ReplyToChatMessage;
use App\Models\Message;
use App\Models\Sprint;
use Illuminate\Support\Carbon;
use Laravel\Ai\Enums\Lab;

/**
 * Chat between the user and the AI colleagues.
 *
 * The user's message is stored at once and answered later by a queued job,
 * because the host has no queue worker that runs all the time.
 */
class ChatService
{
    public const DAILY_LIMIT_REPLY = "I'm in meetings for the rest of the day. Let's pick this up tomorrow.";

    public const FAILED_REPLY = 'Sorry, I got pulled into something. Can you ask me again in a bit?';

    public function send(Sprint $sprint, Colleague $colleague, string $body): Message
    {
        $message = $sprint->messages()->create([
            'colleague' => $colleague,
            'from_user' => true,
            'body' => $body,
        ]);

        ReplyToChatMessage::dispatch($message->id);

        return $message;
    }

    /**
     * Answers the user's message. Returns null when no reply is written.
     *
     * An exception from the AI call is not caught: the job retries.
     */
    public function reply(Message $userMessage): ?Message
    {
        // The job may have waited in the queue: look at the current state.
        $userMessage->refresh();

        if (! $userMessage->from_user || $userMessage->answered_at !== null) {
            return null;
        }

        $sprint = $userMessage->sprint;

        // A paused sprint keeps the message unanswered, so it is answered after resuming.
        if ($sprint->isPaused()) {
            return null;
        }

        if ($sprint->isCompleted() || ! config('sprintlab.ai.enabled')) {
            $this->markAnswered([$userMessage->id]);

            return null;
        }

        $colleague = $userMessage->colleague;
        $agent = ColleagueChat::make(sprint: $sprint, colleague: $colleague);
        $pending = $agent->pendingMessages();

        // Our own message may already have been answered together with another one.
        if ($pending->isEmpty()) {
            return null;
        }

        $pendingIds = $pending->pluck('id')->all();

        if ($this->dailyLimitReached($sprint)) {
            $reply = $this->store($sprint, $colleague, self::DAILY_LIMIT_REPLY);
            $this->markAnswered($pendingIds);

            return $reply;
        }

        $response = $agent->prompt(
            $agent->promptText(),
            provider: Lab::Anthropic,
            model: (string) config('sprintlab.ai.chat_model'),
        );

        $reply = $this->store($sprint, $colleague, trim($response->text), [
            'model' => $response->meta->model,
            'input_tokens' => $response->usage->inputTokens,
            'output_tokens' => $response->usage->outputTokens,
        ]);

        $this->markAnswered($pendingIds);

        return $reply;
    }

    /**
     * Called after the last failed try of the job.
     */
    public function failed(Message $userMessage): void
    {
        if (! $userMessage->from_user || $userMessage->answered_at !== null) {
            return;
        }

        // One apology for everything the user wrote in the meantime.
        $ids = $userMessage->sprint->messages()
            ->where('colleague', $userMessage->colleague->value)
            ->where('from_user', true)
            ->whereNull('answered_at')
            ->pluck('id')
            ->all();

        $this->store($userMessage->sprint, $userMessage->colleague, self::FAILED_REPLY);
        $this->markAnswered([...$ids, $userMessage->id]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function store(Sprint $sprint, Colleague $colleague, string $body, array $extra = []): Message
    {
        return $sprint->messages()->create([
            'colleague' => $colleague,
            'from_user' => false,
            'body' => $body,
            ...$extra,
        ]);
    }

    /**
     * @param  array<int, mixed>  $ids
     */
    private function markAnswered(array $ids): void
    {
        Message::query()->whereIn('id', $ids)->update(['answered_at' => Carbon::now()]);
    }

    /**
     * Only real AI replies cost money, so only those count (they have a model).
     */
    private function dailyLimitReached(Sprint $sprint): bool
    {
        return $sprint->messages()
            ->where('from_user', false)
            ->whereNotNull('model')
            ->where('created_at', '>=', Carbon::now()->startOfDay())
            ->count() >= (int) config('sprintlab.ai.chat_replies_per_sprint_per_day');
    }
}
