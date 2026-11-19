<?php

namespace Tests\Support;

use App\Enums\PullRequestState;
use App\Models\GitHubInstallation;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * Helpers for tests that deal with GitHub: signed webhook calls, payloads
 * shaped like GitHub's, and ready-made records.
 */
trait GitHubFixtures
{
    protected string $webhookSecret = 'test-webhook-secret';

    /**
     * Post a webhook the way GitHub does, signed with the webhook secret.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function githubWebhook(string $event, array $payload, string $deliveryId = 'delivery-1', ?string $signature = null): TestResponse
    {
        config(['github.webhook_secret' => $this->webhookSecret]);

        $body = (string) json_encode($payload);

        return $this->call('POST', '/webhooks/github', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => $event,
            'HTTP_X_GITHUB_DELIVERY' => $deliveryId,
            'HTTP_X_HUB_SIGNATURE_256' => $signature ?? 'sha256='.hash_hmac('sha256', $body, $this->webhookSecret),
        ], $body);
    }

    /**
     * @return array<string, mixed>
     */
    protected function installationPayload(string $action = 'created', int $senderId = 4242): array
    {
        return [
            'action' => $action,
            'installation' => ['id' => 9001, 'account' => ['login' => 'robin', 'id' => 4242]],
            'repositories' => [
                ['id' => 7001, 'full_name' => 'robin/workkit', 'private' => true],
            ],
            'sender' => ['id' => $senderId, 'login' => 'robin', 'type' => 'User'],
        ];
    }

    /**
     * @param  array<string, mixed>  $pullRequest  values that override the default pull request
     * @return array<string, mixed>
     */
    protected function pullRequestPayload(string $action = 'opened', array $pullRequest = []): array
    {
        return [
            'action' => $action,
            'installation' => ['id' => 9001],
            'repository' => [
                'id' => 7001,
                'full_name' => 'robin/workkit',
                'private' => true,
                'owner' => ['login' => 'robin', 'id' => 4242],
            ],
            'pull_request' => array_replace_recursive([
                'number' => 12,
                'title' => 'Fix double approval of lease requests',
                'body' => 'Approving twice no longer creates two contracts.',
                'state' => 'open',
                'merged' => false,
                'draft' => false,
                'user' => ['login' => 'robin', 'type' => 'User'],
                'head' => ['ref' => 'fix/double-approval', 'sha' => str_repeat('a', 40)],
                'base' => ['ref' => 'main'],
            ], $pullRequest),
            'sender' => ['id' => 4242, 'login' => 'robin', 'type' => 'User'],
        ];
    }

    protected function createPullRequest(?User $user = null): PullRequest
    {
        $installation = GitHubInstallation::create([
            'installation_id' => 9001,
            'user_id' => $user?->id,
            'account_login' => 'robin',
            'account_id' => 4242,
        ]);

        $repository = Repository::create([
            'github_id' => 7001,
            'github_installation_id' => $installation->id,
            'full_name' => 'robin/workkit',
            'private' => true,
        ]);

        return PullRequest::create([
            'repository_id' => $repository->id,
            'number' => 12,
            'title' => 'Fix double approval of lease requests',
            'body' => 'Approving twice no longer creates two contracts.',
            'author_login' => 'robin',
            'head_ref' => 'fix/double-approval',
            'head_sha' => str_repeat('a', 40),
            'base_ref' => 'main',
            'state' => PullRequestState::Open,
            'draft' => false,
        ]);
    }

    /**
     * Configure the GitHub App with a freshly generated key pair and return
     * the public key, so a test can verify what the app signs.
     */
    protected function configureGitHubApp(): string
    {
        $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($keyPair, $privateKey);

        config([
            'github.app_id' => '123456',
            'github.private_key' => $privateKey,
            'github.api_url' => 'https://api.github.com',
        ]);

        return openssl_pkey_get_details($keyPair)['key'];
    }
}
