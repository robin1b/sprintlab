<?php

namespace Tests\Feature;

use App\Models\Scenario;
use App\Models\Sprint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DashboardRecommendationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_a_user_without_a_finished_sprint_is_pointed_to_the_demo_first(): void
    {
        Scenario::factory()->create(['level' => 'junior', 'sequence' => 1]);
        $demo = Scenario::factory()->create(['sequence' => null]);

        $this->actingAs(User::factory()->create())->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('recommended_id', $demo->id));
    }

    public function test_a_user_with_a_finished_sprint_gets_the_track_recommended(): void
    {
        $one = Scenario::factory()->create(['level' => 'junior', 'sequence' => 1]);
        $demo = Scenario::factory()->create(['sequence' => null]);
        $user = User::factory()->create();
        Sprint::factory()->for($user)->for($demo)->completed()->create();

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('recommended_id', $one->id));
    }

    public function test_without_a_demo_the_track_is_recommended(): void
    {
        $one = Scenario::factory()->create(['level' => 'junior', 'sequence' => 1]);

        $this->actingAs(User::factory()->create())->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('recommended_id', $one->id));
    }
}
