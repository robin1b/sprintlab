<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class ErrorPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->group(function (): void {
            Route::get('/_test/boom', fn () => throw new RuntimeException('boom'));
            Route::post('/_test/expired', fn () => abort(419));
        });
    }

    public function test_an_address_that_does_not_exist_shows_the_error_page(): void
    {
        $this->get('/does-not-exist')
            ->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page->component('ErrorPage')->where('status', 404));
    }

    public function test_a_signed_in_user_keeps_their_session_on_the_error_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/sprints/999999/evaluation')
            ->assertNotFound()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ErrorPage')
                ->where('status', 404)
                ->where('auth.user.id', $user->id));
    }

    public function test_a_page_the_user_may_not_open_shows_the_error_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.usage'))
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page->component('ErrorPage')->where('status', 403));
    }

    public function test_a_server_error_shows_our_page_with_status_200_because_the_host_replaces_5xx_pages(): void
    {
        config(['app.debug' => false]);

        $this->get('/_test/boom')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ErrorPage')
                ->where('status', 500)
                ->missing('auth'));
    }

    public function test_with_debug_on_a_server_error_keeps_laravels_own_page(): void
    {
        config(['app.debug' => true]);

        $this->get('/_test/boom')->assertStatus(500);
    }

    public function test_json_clients_get_json_not_the_page(): void
    {
        config(['app.debug' => false]);

        $this->getJson('/does-not-exist')->assertNotFound()->assertJsonStructure(['message']);
        $this->getJson('/_test/boom')->assertStatus(500)->assertJsonStructure(['message']);
    }

    public function test_github_gets_the_real_status_for_a_webhook_that_is_refused(): void
    {
        $response = $this->post('/webhooks/github', ['zen' => 'Keep it logically awesome.']);

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        $this->assertStringNotContainsString('ErrorPage', (string) $response->getContent());
    }

    public function test_an_expired_form_goes_back_with_a_message(): void
    {
        $this->from('/privacy')
            ->post('/_test/expired')
            ->assertRedirect('/privacy')
            ->assertInertiaFlash('toast.type', 'error')
            ->assertInertiaFlash('toast.message', 'The page expired. Please try again.');
    }
}
