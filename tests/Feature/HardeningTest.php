<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * On the server the project root is the web root. These tests guard what
 * makes that safe: the blocking rules in .htaccess, the root files, the
 * build in git and the hosts Laravel answers on.
 */
class HardeningTest extends TestCase
{
    private const SECRET_PATHS = [
        '.env',
        '.env.production',
        '.git/config',
        '.gitignore',
        'artisan',
        'composer.json',
        'composer.lock',
        'package.json',
        'phpunit.xml',
        'AGENTS.md',
        'docs/deployment.md',
        'app/Models/User.php',
        'bootstrap/app.php',
        'bootstrap/cache/packages.php',
        'config/app.php',
        'database/database.sqlite',
        'resources/scenarios/demo-sprint.json',
        'routes/web.php',
        'scripts/cron.php',
        'storage/logs/laravel.log',
        'storage/logs/deploy.log',
        'storage/app/private/deploy-state.json',
        'storage/app/private/scenarios/junior-sprint-1.json',
        'storage/framework/sessions/abc',
        'tests/TestCase.php',
        'vendor/autoload.php',
        'vendor/composer/installed.json',
        'node_modules/vue/package.json',
        'public/index.php',
        'backup.zip',
        'dump.sql',
    ];

    private const PUBLIC_PATHS = [
        '',
        'index.php',
        'build/assets/app-DCSn1NIb.js',
        'build/assets/app-DnRPVnc8.css',
        'build/assets/inter-latin-wght-normal.woff2',
        'favicon.ico',
        'favicon.svg',
        'apple-touch-icon.png',
        'robots.txt',
        'dashboard',
        'board',
        'chat/daniel',
        'privacy',
        'settings/profile',
        'webhooks/github',
        'auth/github/callback',
    ];

    public function test_secret_files_are_blocked_when_the_folder_is_the_web_root(): void
    {
        foreach (self::SECRET_PATHS as $path) {
            $this->assertTrue($this->blocked($path, mountedAtRoot: true), "{$path} must be blocked.");
        }
    }

    public function test_secret_files_are_blocked_when_the_folder_is_reached_through_another_address(): void
    {
        // The folder of a subdomain often sits inside the folder of the main
        // domain. Then only the rules that do not look at the full address count.
        foreach (self::SECRET_PATHS as $path) {
            $this->assertTrue($this->blocked($path, mountedAtRoot: false), "{$path} must be blocked under a prefix too.");
        }
    }

    public function test_pages_and_built_assets_stay_reachable(): void
    {
        foreach (self::PUBLIC_PATHS as $path) {
            $this->assertFalse($this->blocked($path, mountedAtRoot: true), "{$path} must stay reachable.");
            $this->assertFalse($this->blocked($path, mountedAtRoot: false), "{$path} must stay reachable under a prefix.");
        }
    }

    public function test_no_route_starts_with_a_blocked_folder_name(): void
    {
        $blocked = $this->blockedFolders();

        $this->assertContains('storage', $blocked);
        $this->assertContains('vendor', $blocked);

        foreach (Route::getRoutes() as $route) {
            /** @var RoutingRoute $route */
            $first = explode('/', trim($route->uri(), '/'))[0];

            $this->assertNotContains($first, $blocked, "Route {$route->uri()} can never be reached on the server: .htaccess blocks /{$first}.");
        }
    }

    public function test_the_root_has_a_front_controller_and_links_to_the_public_files(): void
    {
        $this->assertFileExists(base_path('index.php'));
        $this->assertStringContainsString("__DIR__.'/bootstrap/app.php'", (string) file_get_contents(base_path('index.php')));

        foreach (['build', 'favicon.ico', 'favicon.svg', 'apple-touch-icon.png', 'robots.txt'] as $name) {
            $path = base_path($name);

            // Without symlink support (Windows), git checks a link out as a file with the target in it.
            $target = is_link($path) ? readlink($path) : trim((string) file_get_contents($path));

            $this->assertSame('public/'.$name, str_replace('\\', '/', (string) $target), "{$name} in the root must link to public/{$name}.");
            $this->assertFileExists(public_path($name));
        }
    }

    public function test_the_build_in_git_knows_every_page(): void
    {
        // There is no npm on the server, so public/build is committed. A page
        // without an entry means `npm run build` was forgotten.
        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

        $this->assertIsArray($manifest);
        $this->assertArrayHasKey('resources/js/app.ts', $manifest);

        $pages = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js/pages'), \FilesystemIterator::SKIP_DOTS));

        foreach ($pages as $page) {
            /** @var \SplFileInfo $page */
            if ($page->getExtension() !== 'vue') {
                continue;
            }

            $key = 'resources/js/pages/'.str_replace('\\', '/', substr($page->getPathname(), strlen(resource_path('js/pages')) + 1));

            $this->assertArrayHasKey($key, $manifest, "{$key} is not in public/build: run `npm run build` and commit public/build.");
            $this->assertFileExists(public_path('build/'.$manifest[$key]['file']));
        }
    }

    public function test_only_the_host_of_the_app_url_is_answered_on_the_server(): void
    {
        config(['app.url' => 'https://sprintlab.example.com']);
        $this->app->detectEnvironment(fn (): string => 'production');

        try {
            $this->get('https://sprintlab.example.com/privacy')->assertOk();
            $this->get('https://example.com/sprintlab/privacy')->assertStatus(400);
            $this->get('https://evil.test/privacy')->assertStatus(400);
        } finally {
            // The trusted hosts are kept in a static: do not leak them into other tests.
            Request::setTrustedHosts([]);
        }
    }

    public function test_every_response_carries_the_security_headers(): void
    {
        foreach (['/privacy', '/does-not-exist'] as $address) {
            $this->get($address)
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        }

        $this->get('https://localhost/privacy')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_every_response_carries_a_content_security_policy_with_the_nonce_of_the_page(): void
    {
        $page = $this->get('/privacy')->assertOk();
        $policy = (string) $page->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
        $this->assertStringContainsString('https://avatars.githubusercontent.com', $policy);
        $this->assertStringNotContainsString('unsafe-eval', $policy);
        $this->assertNull($page->headers->get('Content-Security-Policy-Report-Only'));

        preg_match("~script-src 'self' 'nonce-([^']+)'~", $policy, $match);
        $this->assertNotEmpty($match[1] ?? null, 'The policy has no nonce.');

        // Every executable script of the page carries that nonce. The Inertia
        // data is JSON, which the browser does not run.
        preg_match_all('~<script\b[^>]*>~', $page->getContent(), $tags);
        $executable = array_filter($tags[0], fn (string $tag): bool => ! str_contains($tag, 'application/json'));

        $this->assertNotEmpty($executable);

        foreach ($executable as $tag) {
            $this->assertStringContainsString('nonce="'.$match[1].'"', $tag);
        }
    }

    public function test_the_error_page_has_the_policy_and_every_request_gets_a_new_nonce(): void
    {
        $first = (string) $this->get('/does-not-exist')->assertHeader('Content-Security-Policy')->headers->get('Content-Security-Policy');
        $second = (string) $this->get('/does-not-exist')->headers->get('Content-Security-Policy');

        $this->assertNotSame($first, $second);
    }

    public function test_the_report_only_switch_sends_the_same_policy_under_the_other_header(): void
    {
        config(['sprintlab.csp_report_only' => true]);

        $response = $this->get('/privacy');

        $response->assertHeaderMissing('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self' 'nonce-", (string) $response->headers->get('Content-Security-Policy-Report-Only'));
    }

    /**
     * Would the server refuse this path? A model of the rules in .htaccess:
     * FilesMatch looks at the file name, RedirectMatch at the full address
     * (so it only fits when the folder is the web root) and the blocking
     * RewriteRules at the path inside this folder.
     */
    private function blocked(string $path, bool $mountedAtRoot): bool
    {
        $htaccess = (string) file_get_contents(base_path('.htaccess'));

        preg_match_all('/^<FilesMatch "(.+)">\s*$\s*<IfModule mod_authz_core\.c>/m', $htaccess, $files);
        preg_match_all('/^\s*RedirectMatch 404 (\S+)$/m', $htaccess, $redirects);
        preg_match_all('/^\s*RewriteRule (\S+) - \[F,L\]$/m', $htaccess, $rewrites);

        $this->assertCount(1, $files[1]);
        $this->assertNotEmpty($redirects[1]);
        $this->assertNotEmpty($rewrites[1]);

        if ($path !== '' && preg_match('~'.$files[1][0].'~', basename($path)) === 1) {
            return true;
        }

        foreach ($rewrites[1] as $pattern) {
            if (preg_match('~'.$pattern.'~', $path) === 1) {
                return true;
            }
        }

        if ($mountedAtRoot) {
            foreach ($redirects[1] as $pattern) {
                if (preg_match('~'.$pattern.'~', '/'.$path) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function blockedFolders(): array
    {
        preg_match('/^\s*RewriteRule \^\(([^)]+)\)\(\/\|\$\) - \[F,L\]$/m', (string) file_get_contents(base_path('.htaccess')), $match);

        return array_map(fn (string $name): string => str_replace('\\', '', $name), explode('|', $match[1] ?? ''));
    }
}
