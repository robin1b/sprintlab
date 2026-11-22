<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DevLoginLocalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * Routes are registered at boot, so the environment has to be local
     * before the application is created.
     */
    protected function refreshApplication(): void
    {
        putenv('APP_ENV=local');
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'local';

        parent::refreshApplication();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
    }

    public function test_dev_login_creates_and_logs_in_the_demo_user(): void
    {
        $this->assertTrue(app()->environment('local'));

        $this->get(route('dev.login'))->assertRedirect(route('dashboard'));

        $user = User::where('github_id', 1)->firstOrFail();
        $this->assertSame('dev-user', $user->github_username);
        $this->assertSame('Dev User', $user->name);
        $this->assertAuthenticatedAs($user);

        $this->post(route('logout'));
        $this->get(route('dev.login'));
        $this->assertSame(1, User::count());
    }

    public function test_login_page_flags_dev_login(): void
    {
        $this->get(route('login'))
            ->assertInertia(fn ($page) => $page->where('devLogin', true));
    }
}
