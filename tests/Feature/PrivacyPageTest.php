<?php

namespace Tests\Feature;

use App\Http\Controllers\PrivacyController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PrivacyPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_a_guest_can_read_the_privacy_page()
    {
        config([
            'sprintlab.contact_email' => null,
            'sprintlab.contact_url' => 'https://robinbroos.com/contact',
            'sprintlab.retention.webhook_days' => 14,
            'logging.channels.daily.max_files' => 30,
            'session.lifetime' => 120,
        ]);

        $this->get('/privacy')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Privacy')
                ->where('updated_on', PrivacyController::UPDATED_ON)
                ->where('contact_email', null)
                ->where('contact_url', 'https://robinbroos.com/contact')
                ->where('session_minutes', 120)
                ->where('webhook_retention_days', 14)
                ->where('log_retention_days', 30));
    }

    public function test_a_configured_contact_email_is_passed_on()
    {
        config([
            'sprintlab.contact_email' => 'privacy@example.com',
            'session.lifetime' => 90,
        ]);

        $this->get(route('privacy'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Privacy')
                ->where('contact_email', 'privacy@example.com')
                ->where('session_minutes', 90));
    }

    public function test_a_signed_in_user_can_read_it_too()
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('privacy'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Privacy'));
    }
}
