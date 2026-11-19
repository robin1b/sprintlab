<?php

namespace Tests\Feature\GitHub;

use App\Jobs\ProcessGitHubWebhook;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\GitHubFixtures;
use Tests\TestCase;

class WebhookEndpointTest extends TestCase
{
    use GitHubFixtures, RefreshDatabase;

    public function test_a_signed_call_is_stored_and_queued(): void
    {
        Queue::fake();

        $this->githubWebhook('pull_request', $this->pullRequestPayload())
            ->assertStatus(202);

        $delivery = WebhookDelivery::sole();

        $this->assertSame('delivery-1', $delivery->delivery_id);
        $this->assertSame('pull_request', $delivery->event);
        $this->assertSame('opened', $delivery->action);
        $this->assertSame(12, $delivery->payload['pull_request']['number']);

        Queue::assertPushed(ProcessGitHubWebhook::class, fn (ProcessGitHubWebhook $job) => $job->deliveryId === $delivery->id);
    }

    public function test_a_call_with_a_wrong_signature_is_refused(): void
    {
        Queue::fake();

        $this->githubWebhook('pull_request', $this->pullRequestPayload(), signature: 'sha256=wrong')
            ->assertStatus(401);

        $this->assertSame(0, WebhookDelivery::count());
        Queue::assertNothingPushed();
    }

    public function test_a_call_without_a_signature_is_refused(): void
    {
        config(['github.webhook_secret' => $this->webhookSecret]);

        $this->postJson('/webhooks/github', $this->pullRequestPayload(), [
            'X-GitHub-Event' => 'pull_request',
            'X-GitHub-Delivery' => 'delivery-1',
        ])->assertStatus(401);
    }

    public function test_nothing_is_accepted_while_the_secret_is_not_configured(): void
    {
        config(['github.webhook_secret' => null]);

        $this->postJson('/webhooks/github', [], [
            'X-GitHub-Event' => 'ping',
            'X-GitHub-Delivery' => 'delivery-1',
            'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', '[]', ''),
        ])->assertStatus(503);
    }

    public function test_the_same_delivery_is_only_processed_once(): void
    {
        Queue::fake();

        $this->githubWebhook('pull_request', $this->pullRequestPayload())->assertStatus(202);
        $this->githubWebhook('pull_request', $this->pullRequestPayload())->assertStatus(200);

        $this->assertSame(1, WebhookDelivery::count());
        Queue::assertPushed(ProcessGitHubWebhook::class, 1);
    }

    public function test_a_call_without_the_github_headers_is_refused(): void
    {
        config(['github.webhook_secret' => $this->webhookSecret]);

        $body = '{"zen":"Keep it simple."}';

        $this->call('POST', '/webhooks/github', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $this->webhookSecret),
        ], $body)->assertStatus(400);
    }

    public function test_the_endpoint_does_not_start_a_session(): void
    {
        Queue::fake();

        $response = $this->githubWebhook('ping', ['zen' => 'Keep it simple.']);

        $response->assertStatus(202);
        $this->assertSame([], $response->headers->getCookies());
    }
}
