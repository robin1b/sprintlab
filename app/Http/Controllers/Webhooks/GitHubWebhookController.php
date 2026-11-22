<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessGitHubWebhook;
use App\Models\WebhookDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GitHubWebhookController extends Controller
{
    /**
     * Store the call and answer right away; a queued job does the work.
     * GitHub expects an answer within 10 seconds and may send a call twice.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $deliveryId = (string) $request->header('X-GitHub-Delivery');
        $event = (string) $request->header('X-GitHub-Event');

        if ($deliveryId === '' || $event === '') {
            return response()->json(['message' => 'Missing GitHub headers.'], 400);
        }

        $delivery = WebhookDelivery::firstOrCreate(
            ['delivery_id' => $deliveryId],
            [
                'event' => $event,
                'action' => $request->input('action'),
                'payload' => $request->json()->all(),
            ],
        );

        // Seen before: GitHub is retrying, nothing to do.
        if (! $delivery->wasRecentlyCreated) {
            return response()->json(['message' => 'Already received.']);
        }

        ProcessGitHubWebhook::dispatch($delivery->id);

        return response()->json(['message' => 'Accepted.'], 202);
    }
}
