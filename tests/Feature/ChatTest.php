<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get(route('chat'))->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_chat()
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('chat'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Chat'));
    }
}
