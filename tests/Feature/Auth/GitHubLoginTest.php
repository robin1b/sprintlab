<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\TestCase;

class GitHubLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function fakeGitHub(array $attributes = []): void
    {
        Socialite::fake('github', SocialiteUser::fake(array_merge([
            'id' => '4242',
            'nickname' => 'octocat',
            'name' => 'The Octocat',
            'email' => 'octo@example.com',
            'avatar' => 'https://example.com/octo.png',
        ], $attributes)));
    }

    public function test_login_page_renders_for_guests(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('auth/Login')
                ->where('devLogin', false));
    }

    public function test_authenticated_users_are_redirected_away_from_login(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('login'))
            ->assertRedirect();
    }

    public function test_redirect_goes_to_github(): void
    {
        Socialite::fake('github');

        $this->get(route('auth.github.redirect'))
            ->assertRedirect('https://socialite.fake/github/authorize');
    }

    public function test_callback_creates_a_user(): void
    {
        $this->fakeGitHub();

        $this->get(route('auth.github.callback'))
            ->assertRedirect(route('dashboard'));

        $user = User::where('github_id', 4242)->firstOrFail();
        $this->assertSame('octocat', $user->github_username);
        $this->assertSame('The Octocat', $user->name);
        $this->assertSame('octo@example.com', $user->email);
        $this->assertSame('https://example.com/octo.png', $user->avatar_url);
        $this->assertAuthenticatedAs($user);
    }

    public function test_callback_falls_back_to_username_when_name_is_missing(): void
    {
        $this->fakeGitHub(['name' => null]);

        $this->get(route('auth.github.callback'));

        $this->assertSame('octocat', User::where('github_id', 4242)->firstOrFail()->name);
    }

    public function test_callback_updates_an_existing_user(): void
    {
        $existing = User::factory()->create([
            'github_id' => 4242,
            'github_username' => 'old-name',
            'name' => 'Old Name',
            'email' => 'mine@example.com',
            'avatar_url' => null,
        ]);

        $this->fakeGitHub();

        $this->get(route('auth.github.callback'))->assertRedirect(route('dashboard'));

        $this->assertSame(1, User::count());
        $existing->refresh();
        $this->assertSame('octocat', $existing->github_username);
        $this->assertSame('The Octocat', $existing->name);
        $this->assertSame('https://example.com/octo.png', $existing->avatar_url);
        $this->assertSame('mine@example.com', $existing->email);
        $this->assertAuthenticatedAs($existing);
    }

    public function test_callback_stores_no_email_when_another_user_has_it(): void
    {
        $other = User::factory()->create(['email' => 'octo@example.com']);

        $this->fakeGitHub();

        $this->get(route('auth.github.callback'))->assertRedirect(route('dashboard'));

        $user = User::where('github_id', 4242)->firstOrFail();
        $this->assertNull($user->email);
        $this->assertNotSame($other->id, $user->id);
        $this->assertAuthenticatedAs($user);
    }

    public function test_callback_redirects_to_the_intended_url(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));

        $this->fakeGitHub();

        $this->get(route('auth.github.callback'))->assertRedirect(route('dashboard'));
    }

    public function test_cancelled_callback_redirects_to_login_with_error(): void
    {
        $this->get(route('auth.github.callback', ['error' => 'access_denied']))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');

        $this->assertGuest();
    }

    public function test_failed_callback_redirects_to_login_with_error(): void
    {
        Socialite::fake('github', fn () => throw new \RuntimeException('GitHub is down'));

        $this->get(route('auth.github.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');

        $this->assertGuest();
    }

    public function test_invalid_state_redirects_to_login_with_error(): void
    {
        Socialite::fake('github', fn () => throw new InvalidStateException);

        $this->get(route('auth.github.callback'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('error');

        $this->assertGuest();
    }

    public function test_login_page_shows_the_flash_error(): void
    {
        $this->withSession(['error' => 'Something went wrong.'])
            ->get(route('login'))
            ->assertInertia(fn ($page) => $page->where('flash.error', 'Something went wrong.'));
    }

    public function test_users_can_log_out(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('logout'))
            ->assertRedirect('/');

        $this->assertGuest();
    }

    public function test_guests_cannot_log_out(): void
    {
        $this->post(route('logout'))->assertRedirect(route('login'));
    }
}
