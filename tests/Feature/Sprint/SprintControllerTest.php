<?php

namespace Tests\Feature\Sprint;

use App\Enums\SprintStatus;
use App\Models\Scenario;
use App\Models\Sprint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GitHubFixtures;
use Tests\Support\SprintFixtures;
use Tests\TestCase;

class SprintControllerTest extends TestCase
{
    use GitHubFixtures;
    use RefreshDatabase;
    use SprintFixtures;

    public function test_guests_are_redirected_to_the_login_page()
    {
        $sprint = Sprint::factory()->create();

        $this->post(route('sprints.store'), ['scenario_id' => 1])->assertRedirect(route('login'));
        $this->post(route('sprints.pause', $sprint))->assertRedirect(route('login'));
        $this->post(route('sprints.resume', $sprint))->assertRedirect(route('login'));
    }

    public function test_a_user_can_start_a_sprint()
    {
        $scenario = $this->demoScenario();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('sprints.store'), ['scenario_id' => $scenario->id])
            ->assertRedirect(route('board'));

        $sprint = $user->sprints()->sole();
        $this->assertSame(SprintStatus::Active, $sprint->status);
        $this->assertSame($scenario->id, $sprint->scenario_id);
        $this->assertSame(3, $sprint->tickets()->whereNotNull('released_at')->count());
    }

    public function test_a_junior_can_start_a_track_scenario_of_their_level_and_a_demo()
    {
        $track = Scenario::factory()->create(['level' => 'junior', 'sequence' => 1]);
        $demo = Scenario::factory()->create(['level' => 'senior', 'sequence' => null]);
        $user = User::factory()->create(['level' => 'junior']);
        $this->giveRepository($user);

        $this->actingAs($user)->post(route('sprints.store'), ['scenario_id' => $track->id])->assertRedirect(route('board'));
        $this->assertSame($track->id, $user->sprints()->sole()->scenario_id);

        $other = User::factory()->create(['level' => 'junior']);
        $this->actingAs($other)->post(route('sprints.store'), ['scenario_id' => $demo->id])->assertRedirect(route('board'));
        $this->assertSame($demo->id, $other->sprints()->sole()->scenario_id);
    }

    public function test_a_track_sprint_needs_a_connected_repository_but_the_demo_does_not()
    {
        $track = Scenario::factory()->create(['level' => 'junior', 'sequence' => 1]);
        $demo = Scenario::factory()->create(['level' => 'junior', 'sequence' => null]);
        $user = User::factory()->create(['level' => 'junior']);

        $this->actingAs($user)
            ->post(route('sprints.store'), ['scenario_id' => $track->id])
            ->assertSessionHasErrors(['scenario_id' => 'Connect your practice repository first.']);
        $this->assertSame(0, $user->sprints()->count());

        $this->giveRepository($user, suspended: true);
        $this->post(route('sprints.store'), ['scenario_id' => $track->id])
            ->assertSessionHasErrors(['scenario_id' => 'Connect your practice repository first.']);

        $this->post(route('sprints.store'), ['scenario_id' => $demo->id])->assertRedirect(route('board'));
        $this->assertSame($demo->id, $user->sprints()->sole()->scenario_id);
    }

    public function test_a_junior_cannot_start_a_medior_track_scenario()
    {
        $medior = Scenario::factory()->create(['level' => 'medior', 'sequence' => 1]);
        $user = User::factory()->create(['level' => 'junior']);

        $this->actingAs($user)
            ->post(route('sprints.store'), ['scenario_id' => $medior->id])
            ->assertSessionHasErrors(['scenario_id' => 'This sprint is not available at your level.']);

        $this->assertSame(0, $user->sprints()->count());
    }

    public function test_a_second_start_is_refused_with_a_flash_error()
    {
        $sprint = $this->startDemoSprint();

        $this->actingAs($sprint->user)
            ->from(route('dashboard'))
            ->post(route('sprints.store'), ['scenario_id' => $sprint->scenario_id])
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error', 'You already have a sprint that is not finished.');

        $this->assertSame(1, $sprint->user->sprints()->count());
    }

    public function test_the_scenario_must_exist()
    {
        $this->actingAs(User::factory()->create())
            ->post(route('sprints.store'), ['scenario_id' => 999])
            ->assertSessionHasErrors('scenario_id');

        $this->actingAs(User::factory()->create())
            ->post(route('sprints.store'))
            ->assertSessionHasErrors('scenario_id');
    }

    public function test_a_user_can_pause_and_resume_the_sprint()
    {
        $sprint = $this->startDemoSprint();
        $this->actingAs($sprint->user);

        $this->post(route('sprints.pause', $sprint))->assertRedirect();
        $this->assertSame(SprintStatus::Paused, $sprint->fresh()->status);

        $this->post(route('sprints.resume', $sprint))->assertRedirect();
        $this->assertSame(SprintStatus::Active, $sprint->fresh()->status);
    }

    public function test_pausing_a_paused_sprint_is_a_flash_error()
    {
        $sprint = Sprint::factory()->paused()->create();

        $this->actingAs($sprint->user)
            ->from(route('dashboard'))
            ->post(route('sprints.pause', $sprint))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error', 'Only an active sprint can be paused.');
    }

    public function test_resuming_an_active_sprint_is_a_flash_error()
    {
        $sprint = $this->startDemoSprint();

        $this->actingAs($sprint->user)
            ->post(route('sprints.resume', $sprint))
            ->assertSessionHas('error', 'Only a paused sprint can be resumed.');
    }

    public function test_someone_elses_sprint_cannot_be_paused_or_resumed()
    {
        $sprint = $this->startDemoSprint();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->post(route('sprints.pause', $sprint))->assertForbidden();
        $this->assertSame(SprintStatus::Active, $sprint->fresh()->status);

        $sprint->update(['status' => SprintStatus::Paused, 'paused_at' => now()]);

        $this->actingAs($stranger)->post(route('sprints.resume', $sprint))->assertForbidden();
        $this->assertSame(SprintStatus::Paused, $sprint->fresh()->status);
    }
}
