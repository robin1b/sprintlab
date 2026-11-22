<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Services\GitHub\WebhookHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

class ProcessGitHubWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $deliveryId) {}

    public function handle(WebhookHandler $handler): void
    {
        $delivery = WebhookDelivery::find($this->deliveryId);

        if ($delivery === null || $delivery->processed_at !== null) {
            return;
        }

        try {
            $handler->handle($delivery);
        } catch (Throwable $exception) {
            $delivery->update(['error' => Str::limit($exception->getMessage(), 1000)]);

            throw $exception;
        }

        $delivery->update(['processed_at' => Carbon::now(), 'error' => null]);
    }
}
