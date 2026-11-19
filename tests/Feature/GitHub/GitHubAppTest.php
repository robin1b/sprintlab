<?php

namespace Tests\Feature\GitHub;

use App\Services\GitHub\GitHubApp;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Support\GitHubFixtures;
use Tests\TestCase;

class GitHubAppTest extends TestCase
{
    use GitHubFixtures;

    public function test_it_signs_a_short_lived_token_as_the_app(): void
    {
        $publicKey = $this->configureGitHubApp();

        $claims = JWT::decode(app(GitHubApp::class)->jwt(), new Key($publicKey, 'RS256'));

        $this->assertSame('123456', $claims->iss);
        // GitHub refuses app tokens that live longer than 10 minutes.
        $this->assertLessThanOrEqual(600, $claims->exp - $claims->iat);
    }

    public function test_the_private_key_may_be_stored_base64_encoded_or_with_escaped_line_breaks(): void
    {
        $publicKey = $this->configureGitHubApp();
        $pem = (string) config('github.private_key');

        config(['github.private_key' => base64_encode($pem)]);
        $this->assertNotEmpty(JWT::decode(app(GitHubApp::class)->jwt(), new Key($publicKey, 'RS256'))->iss);

        config(['github.private_key' => str_replace("\n", '\n', $pem)]);
        $this->assertNotEmpty(JWT::decode(app(GitHubApp::class)->jwt(), new Key($publicKey, 'RS256'))->iss);
    }

    public function test_it_refuses_to_work_without_configuration(): void
    {
        config(['github.app_id' => null, 'github.private_key' => null]);

        $this->assertFalse(app(GitHubApp::class)->isConfigured());

        $this->expectException(RuntimeException::class);

        app(GitHubApp::class)->jwt();
    }

    public function test_it_asks_for_an_installation_token_once_and_caches_it(): void
    {
        $this->configureGitHubApp();

        Http::fake([
            'api.github.com/app/installations/9001/access_tokens' => Http::response([
                'token' => 'installation-token',
                'expires_at' => now()->addHour()->toIso8601String(),
            ], 201),
        ]);

        $app = app(GitHubApp::class);

        $this->assertSame('installation-token', $app->installationToken(9001));
        $this->assertSame('installation-token', $app->installationToken(9001));

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && str_starts_with($request->header('Authorization')[0], 'Bearer ')
            && $request->header('X-GitHub-Api-Version')[0] === '2022-11-28');
    }

    public function test_it_lists_the_changed_files_and_submits_a_review(): void
    {
        $this->configureGitHubApp();

        Http::fake([
            'api.github.com/app/installations/*/access_tokens' => Http::response([
                'token' => 'installation-token',
                'expires_at' => now()->addHour()->toIso8601String(),
            ], 201),
            'api.github.com/repos/robin/workkit/pulls/12/files*' => Http::response([
                ['filename' => 'app/Lease.php', 'status' => 'modified', 'additions' => 2, 'deletions' => 1, 'patch' => "@@ -1,1 +1,2 @@\n-a\n+b\n+c"],
                ['filename' => 'public/logo.png', 'status' => 'added', 'additions' => 0, 'deletions' => 0],
            ]),
            'api.github.com/repos/robin/workkit/pulls/12/reviews' => Http::response(['id' => 555]),
        ]);

        $client = app(GitHubApp::class)->installation(9001);

        $files = $client->pullRequestFiles('robin/workkit', 12);

        $this->assertCount(2, $files);
        $this->assertSame('app/Lease.php', $files[0]['filename']);
        $this->assertNull($files[1]['patch']);

        $reviewId = $client->createReview('robin/workkit', 12, str_repeat('a', 40), 'REQUEST_CHANGES', 'Please add a test.', [
            ['path' => 'app/Lease.php', 'line' => 2, 'body' => 'Why this value?'],
        ]);

        $this->assertSame(555, $reviewId);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/pulls/12/reviews')
            && $request['event'] === 'REQUEST_CHANGES'
            && $request['commit_id'] === str_repeat('a', 40)
            && $request['comments'] === [['path' => 'app/Lease.php', 'line' => 2, 'side' => 'RIGHT', 'body' => 'Why this value?']]
            && $request->header('Authorization')[0] === 'Bearer installation-token');
    }
}
