<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BoardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get(route('board'))->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_board()
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('board'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Board'));
    }
}
