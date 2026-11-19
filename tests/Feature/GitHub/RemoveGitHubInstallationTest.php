<?php

namespace Tests\Feature\GitHub;

use App\Jobs\RemoveGitHubInstallation;
use App\Services\GitHub\GitHubApp;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\GitHubFixtures;
use Tests\TestCase;

class RemoveGitHubInstallationTest extends TestCase
{
    use GitHubFixtures;

    private function run_job(int $id = 9001): void
    {
        (new RemoveGitHubInstallation($id))->handle(app(GitHubApp::class));
    }

    public function test_it_deletes_the_installation_with_the_app_jwt(): void
    {
        $publicKey = $this->configureGitHubApp();
        Cache::put('github.installation-token.9001', 'cached-token', 600);
        Http::fake(['api.github.com/app/installations/9001' => Http::response('', 204)]);

        $this->run_job();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($publicKey): bool {
            $jwt = str_replace('Bearer ', '', $request->header('Authorization')[0]);
            $claims = JWT::decode($jwt, new Key($publicKey, 'RS256'));

            return $request->method() === 'DELETE'
                && $request->url() === 'https://api.github.com/app/installations/9001'
                && $claims->iss === '123456';
        });
        $this->assertNull(Cache::get('github.installation-token.9001'));
    }

    public function test_an_installation_that_is_already_gone_is_fine(): void
    {
        $this->configureGitHubApp();
        Http::fake(['api.github.com/app/installations/9001' => Http::response(['message' => 'Not Found'], 404)]);

        $this->run_job();

        Http::assertSentCount(1);
    }

    public function test_other_failures_throw_so_the_job_retries(): void
    {
        $this->configureGitHubApp();
        Http::fake(['api.github.com/app/installations/9001' => Http::response('', 500)]);

        $this->expectException(RequestException::class);

        $this->run_job();
    }

    public function test_nothing_is_sent_when_the_app_is_not_configured(): void
    {
        config(['github.app_id' => null, 'github.private_key' => null]);
        Http::fake();

        $this->run_job();

        Http::assertNothingSent();
    }

    public function test_the_job_retries_three_times_with_a_backoff(): void
    {
        $job = new RemoveGitHubInstallation(9001);

        $this->assertSame(3, $job->tries);
        $this->assertNotEmpty($job->backoff());
    }
}
