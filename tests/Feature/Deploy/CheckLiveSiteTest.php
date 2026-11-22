<?php

namespace Tests\Feature\Deploy;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CheckLiveSiteTest extends TestCase
{
    private const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Strict-Transport-Security' => 'max-age=31536000',
        'Content-Security-Policy' => "default-src 'self'",
    ];

    /**
     * @param  array<string, mixed>  $overrides  url pattern => response
     */
    private function fakeSite(array $overrides = [], array $headers = self::HEADERS): void
    {
        Http::fake($overrides + [
            'http://*' => Http::response('', 301, ['Location' => 'https://sprintlab.example.com/']),
            'https://sprintlab.example.com/up' => Http::response('ok', 200),
            'https://sprintlab.example.com' => Http::response('page', 200, $headers),
            'https://sprintlab.example.com/' => Http::response('page', 200, $headers),
            'https://sprintlab.example.com/*' => Http::response('Forbidden', 403),
            'https://robinbroos.com/sprintlab' => Http::response('Bad request', 400),
            'https://robinbroos.com/sprintlab/*' => Http::response('Not found', 404),
        ]);
    }

    public function test_a_healthy_site_passes(): void
    {
        $this->fakeSite();

        $this->artisan('sprintlab:check-live', ['url' => 'https://sprintlab.example.com'])
            ->expectsOutputToContain('OK')
            ->assertExitCode(0);
    }

    public function test_the_default_address_is_app_url(): void
    {
        config(['app.url' => 'https://sprintlab.example.com']);
        $this->fakeSite();

        $this->artisan('sprintlab:check-live')->assertExitCode(0);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://sprintlab.example.com/up');
    }

    public function test_a_secret_file_that_is_served_fails(): void
    {
        $this->fakeSite(['https://sprintlab.example.com/.env' => Http::response('APP_KEY=secret', 200)]);

        $this->artisan('sprintlab:check-live', ['url' => 'https://sprintlab.example.com'])
            ->expectsOutputToContain('FAIL')
            ->assertExitCode(1);
    }

    public function test_a_missing_header_fails(): void
    {
        $headers = self::HEADERS;
        unset($headers['Content-Security-Policy']);
        $this->fakeSite(headers: $headers);

        $this->artisan('sprintlab:check-live', ['url' => 'https://sprintlab.example.com'])
            ->expectsOutputToContain('FAIL')
            ->assertExitCode(1);
    }

    public function test_http_that_does_not_redirect_fails(): void
    {
        $this->fakeSite(['http://*' => Http::response('page', 200)]);

        $this->artisan('sprintlab:check-live', ['url' => 'https://sprintlab.example.com'])->assertExitCode(1);
    }

    public function test_a_connection_error_is_a_failed_row_not_a_crash(): void
    {
        Http::fake(fn () => throw new ConnectionException('Could not resolve host'));

        $this->artisan('sprintlab:check-live', ['url' => 'https://sprintlab.example.com'])
            ->expectsOutputToContain('Could not resolve host')
            ->assertExitCode(1);
    }

    public function test_the_main_domain_is_checked_too(): void
    {
        $this->fakeSite();

        $this->artisan('sprintlab:check-live', ['url' => 'https://sprintlab.example.com', '--main-domain' => 'https://robinbroos.com/sprintlab'])
            ->assertExitCode(0);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://robinbroos.com/sprintlab/storage/logs/deploy.log');
    }

    public function test_sprintlab_answering_under_the_main_domain_fails(): void
    {
        $this->fakeSite(['https://robinbroos.com/sprintlab/' => Http::response('Sprintlab', 200)]);

        $this->artisan('sprintlab:check-live', ['url' => 'https://sprintlab.example.com', '--main-domain' => 'https://robinbroos.com/sprintlab'])
            ->assertExitCode(1);
    }

    public function test_a_secret_file_under_the_main_domain_fails(): void
    {
        $this->fakeSite(['https://robinbroos.com/sprintlab/.env' => Http::response('APP_KEY=secret', 200)]);

        $this->artisan('sprintlab:check-live', ['url' => 'https://sprintlab.example.com', '--main-domain' => 'https://robinbroos.com/sprintlab'])
            ->assertExitCode(1);
    }
}
