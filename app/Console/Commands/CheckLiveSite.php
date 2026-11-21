<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Looks at the live site from the outside, the way a stranger would: the
 * security headers, the http to https redirect and the files that must never
 * be served. Run it on your own computer after every deployment.
 */
#[Signature('sprintlab:check-live {url? : Address of the live site (default: APP_URL)} {--main-domain= : Address of the site inside the main domain, for example https://robinbroos.com/sprintlab}')]
#[Description('Check the live site from outside: security headers, https redirect and blocked files')]
class CheckLiveSite extends Command
{
    private const HEADERS = [
        'X-Content-Type-Options',
        'X-Frame-Options',
        'Referrer-Policy',
        'Strict-Transport-Security',
        'Content-Security-Policy',
    ];

    private const BLOCKED_PATHS = [
        '/.env',
        '/composer.json',
        '/composer.lock',
        '/artisan',
        '/storage/logs/laravel.log',
        '/storage/logs/deploy.log',
        '/storage/app/private/deploy-state.json',
        '/vendor/autoload.php',
        '/public/index.php',
        '/scripts/cron.php',
        '/.git/config',
        '/database/database.sqlite',
        '/resources/scenarios/demo-sprint.json',
    ];

    /**
     * @var list<array{string, string, string, bool}>
     */
    private array $rows = [];

    public function handle(): int
    {
        $base = rtrim((string) ($this->argument('url') ?: config('app.url')), '/');

        $this->checkHome($base);
        $this->checkHttpRedirect($base);

        foreach ($this->blockedPaths() as $path) {
            $this->checkBlocked($base, $path);
        }

        $this->checkStatus($base.'/up', 'GET /up', [200]);

        $main = rtrim((string) $this->option('main-domain'), '/');

        if ($main !== '') {
            foreach ($this->blockedPaths() as $path) {
                $this->checkBlocked($main, $path);
            }

            $this->checkMainDomainRoot($main);
        }

        $this->table(
            ['Check', 'Expected', 'Got', 'Result'],
            array_map(fn (array $row): array => [$row[0], $row[1], $row[2], $row[3] ? 'OK' : 'FAIL'], $this->rows),
        );

        $failed = count(array_filter($this->rows, fn (array $row): bool => ! $row[3]));

        if ($failed > 0) {
            $this->error("{$failed} of ".count($this->rows).' checks failed.');

            return self::FAILURE;
        }

        $this->info('All '.count($this->rows).' checks passed.');

        return self::SUCCESS;
    }

    /**
     * A file that does not exist answers 404 whether its folder is blocked or
     * not, so the list also names the log file that certainly exists: today's.
     *
     * @return list<string>
     */
    private function blockedPaths(): array
    {
        return [...self::BLOCKED_PATHS, '/storage/logs/laravel-'.now()->format('Y-m-d').'.log'];
    }

    private function checkHome(string $base): void
    {
        $response = $this->get($base.'/');

        if (! $response instanceof Response) {
            $this->rows[] = ['GET /', '200', (string) $response, false];

            return;
        }

        $this->rows[] = ['GET /', '200', (string) $response->status(), $response->status() === 200];

        foreach (self::HEADERS as $header) {
            $this->rows[] = [
                "Header {$header}",
                'present',
                $response->hasHeader($header) ? 'present' : 'missing',
                $response->hasHeader($header),
            ];
        }
    }

    private function checkHttpRedirect(string $base): void
    {
        $label = 'http:// redirects to https://';
        $parts = parse_url($base);

        if (! isset($parts['host'])) {
            $this->rows[] = [$label, '301 to https', 'no valid address', false];

            return;
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = rtrim($parts['path'] ?? '', '/');
        $http = 'http://'.$parts['host'].$path.'/';
        $expected = 'https://'.$parts['host'].$port.$path.'/';

        $response = $this->get($http);

        if (! $response instanceof Response) {
            $this->rows[] = [$label, "301 to {$expected}", (string) $response, false];

            return;
        }

        $location = $response->header('Location');
        $ok = $response->status() === 301 && str_starts_with($location, 'https://'.$parts['host']);

        $this->rows[] = [$label, "301 to {$expected}", $response->status().($location !== '' ? " to {$location}" : ''), $ok];
    }

    private function checkBlocked(string $base, string $path): void
    {
        $this->checkStatus($base.$path, 'GET '.$base.$path, [403, 404]);
    }

    private function checkMainDomainRoot(string $main): void
    {
        $label = 'GET '.$main.'/';
        $response = $this->get($main.'/');

        if (! $response instanceof Response) {
            $this->rows[] = [$label, '400, 403 or 404, not Sprintlab', (string) $response, false];

            return;
        }

        $this->rows[] = [
            $label,
            '400, 403 or 404, not Sprintlab',
            (string) $response->status(),
            in_array($response->status(), [400, 403, 404], true),
        ];
    }

    /**
     * @param  list<int>  $expected
     */
    private function checkStatus(string $url, string $label, array $expected): void
    {
        $response = $this->get($url);
        $wanted = implode(' or ', array_map('strval', $expected));

        if (! $response instanceof Response) {
            $this->rows[] = [$label, $wanted, (string) $response, false];

            return;
        }

        $this->rows[] = [$label, $wanted, (string) $response->status(), in_array($response->status(), $expected, true)];
    }

    /**
     * The response, or the error message when the request could not be made.
     */
    private function get(string $url): Response|string
    {
        try {
            return Http::timeout(10)->withoutRedirecting()->get($url);
        } catch (Throwable $e) {
            return 'error: '.$e->getMessage();
        }
    }
}
