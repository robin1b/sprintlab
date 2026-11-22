<?php

namespace Tests\Feature\Tour;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TourTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_user_has_seen_no_part_of_the_tour(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('auth.user.tours_done', []));
    }

    public function test_finishing_a_part_is_remembered_once(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->from('/board')->post('/tour/board')->assertRedirect('/board');
        $this->actingAs($user)->from('/board')->post('/tour/board');
        $this->actingAs($user)->from('/chat')->post('/tour/chat');

        $this->assertSame(['board', 'chat'], $user->fresh()->tours_done);
    }

    public function test_an_unknown_part_is_not_found(): void
    {
        $this->actingAs(User::factory()->create())->post('/tour/settings')->assertNotFound();
    }

    public function test_restarting_forgets_every_part(): void
    {
        $user = User::factory()->create(['tours_done' => ['overview', 'board']]);

        $this->actingAs($user)->delete('/tour')->assertRedirect('/dashboard');

        $this->assertSame([], $user->fresh()->tours_done);
    }

    public function test_guests_cannot_touch_the_tour(): void
    {
        $this->post('/tour/board')->assertRedirect('/login');
    }
}
