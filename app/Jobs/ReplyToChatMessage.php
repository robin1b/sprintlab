<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\Chat\ChatService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ReplyToChatMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 90;

    public function __construct(public int $messageId) {}

    public function handle(ChatService $chat): void
    {
        $message = Message::find($this->messageId);

        if ($message === null) {
            return;
        }

        $chat->reply($message);
    }

    /**
     * Runs after the last try: the user must not wait for ever on a silent colleague.
     */
    public function failed(?Throwable $exception): void
    {
        $message = Message::find($this->messageId);

        if ($message !== null) {
            app(ChatService::class)->failed($message);
        }
    }
}
