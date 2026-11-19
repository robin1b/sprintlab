<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_admin_gate_follows_the_configured_github_ids(): void
    {
        config(['sprintlab.admin_github_ids' => [4242]]);

        $this->assertTrue(Gate::forUser(User::factory()->make(['github_id' => 4242]))->allows('admin'));
        $this->assertFalse(Gate::forUser(User::factory()->make(['github_id' => 4243]))->allows('admin'));
    }

    public function test_nobody_is_admin_without_configured_ids(): void
    {
        config(['sprintlab.admin_github_ids' => []]);

        $this->assertFalse(Gate::forUser(User::factory()->make(['github_id' => 0]))->allows('admin'));
    }

    public function test_the_shared_prop_tells_whether_the_user_is_admin(): void
    {
        config(['sprintlab.admin_github_ids' => [4242]]);

        $this->actingAs(User::factory()->create(['github_id' => 4242]))->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('auth.is_admin', true));

        $this->actingAs(User::factory()->create(['github_id' => 1]))->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('auth.is_admin', false));
    }
}
