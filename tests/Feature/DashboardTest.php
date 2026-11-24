<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\Scenario;
use App\Models\Sprint;
use App\Models\SprintEvaluation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\EvaluationFixtures;
use Tests\Support\SprintFixtures;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use EvaluationFixtures;
    use RefreshDatabase;
    use SprintFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    public function test_without_a_scenario_or_sprint_there_is_nothing_to_start()
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('scenarios', [])
                ->where('recommended_id', null)
                ->where('overview', null)
                ->where('completed', null)
                ->has('team', 5));
    }

    public function test_without_a_sprint_the_demo_is_offered_and_recommended()
    {
        $scenario = $this->demoScenario();
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('scenarios', 1)
                ->where('scenarios.0.id', $scenario->id)
                ->where('scenarios.0.title', 'Demo sprint')
                ->where('scenarios.0.duration_days', 2)
                ->where('scenarios.0.sequence', null)
                ->where('scenarios.0.is_demo', true)
                ->where('scenarios.0.result', null)
                ->where('recommended_id', $scenario->id)
                ->where('overview', null));
    }

    public function test_with_a_sprint_it_shows_counts_todays_tickets_and_latest_messages()
    {
        $sprint = $this->startDemoSprint();
        Message::factory()->count(4)->create([
            'sprint_id' => $sprint->id,
            'body' => str_repeat('long ', 60),
        ]);

        $this->actingAs($sprint->user);

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('overview.ticket_counts.0.value', 'todo')
                ->where('overview.ticket_counts.0.count', 3)
                ->where('overview.ticket_counts.3.count', 0)
                ->has('overview.todays_tickets', 3)
                ->where('overview.todays_tickets.1.key', 'WK-1')
                ->has('overview.latest_messages', 3)
                ->where('overview.latest_messages.0.body', fn (string $body) => mb_strlen($body) <= 143));
    }

    public function test_a_completed_sprint_is_reported_and_can_be_started_again()
    {
        $this->demoScenario();
        $sprint = Sprint::factory()->completed()->create(['scenario_id' => $this->demoScenario()->id]);
        $this->actingAs($sprint->user);

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('overview', null)
                ->where('completed.scenario_title', 'Demo sprint')
                ->where('scenarios.0.title', 'Demo sprint'));
    }

    private function track(int $sequence, string $level = 'junior'): Scenario
    {
        return Scenario::factory()->create(['level' => $level, 'sequence' => $sequence, 'title' => "{$level} {$sequence}"]);
    }

    private function play(User $user, Scenario $scenario, ?bool $passed = null, int $overall = 80): Sprint
    {
        $sprint = Sprint::factory()->for($user)->for($scenario)->completed()->create();

        if ($passed !== null) {
            SprintEvaluation::factory()->completed($passed)->create(['sprint_id' => $sprint->id, 'overall' => $overall, 'passed' => $passed]);
        }

        return $sprint;
    }

    public function test_the_track_of_the_level_comes_first_by_sequence_then_the_demos()
    {
        $demo = Scenario::factory()->create(['title' => 'Demo', 'sequence' => null]);
        $two = $this->track(2);
        $one = $this->track(1);
        $this->track(1, 'medior');
        $extra = Scenario::factory()->create(['title' => 'Extra', 'level' => 'senior', 'sequence' => null]);

        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('scenarios', fn ($offers) => collect($offers)->pluck('id')->all() === [$one->id, $two->id, $demo->id, $extra->id])
            ->where('scenarios.0.sequence', 1)
            ->where('scenarios.0.is_demo', false)
            ->where('scenarios.2.is_demo', true));
    }

    public function test_a_medior_user_does_not_see_the_junior_track_but_does_see_the_demo()
    {
        $this->track(1);
        $medior = $this->track(1, 'medior');
        $demo = Scenario::factory()->create(['sequence' => null]);

        $this->actingAs(User::factory()->create(['level' => 'medior']));

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('scenarios', fn ($offers) => collect($offers)->pluck('id')->all() === [$medior->id, $demo->id]));
    }

    public function test_the_first_track_sprint_that_is_not_passed_is_recommended()
    {
        $user = User::factory()->create();
        $one = $this->track(1);
        $two = $this->track(2);
        $this->track(3);
        $demo = Scenario::factory()->create(['sequence' => null]);
        $this->actingAs($user);

        // Nothing finished yet: the demo comes first.
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('recommended_id', $demo->id));

        // The demo is played: now the track, from its first sprint.
        $this->play($user, $demo);
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('recommended_id', $one->id));

        $this->play($user, $one, true);
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('recommended_id', $two->id));

        // Played but not passed: still the one to do.
        $this->play($user, $two, false, 55);
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('recommended_id', $two->id));
    }

    public function test_when_the_whole_track_is_passed_the_first_scenario_never_played_is_recommended()
    {
        $user = User::factory()->create();
        $one = $this->track(1);
        $demo = Scenario::factory()->create(['sequence' => null]);
        $this->play($user, $one, true);
        $this->actingAs($user);

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('recommended_id', $demo->id));

        // Everything played: back to the first offer.
        $this->play($user, $demo);
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('recommended_id', $one->id));
    }

    public function test_an_offer_has_the_result_of_the_latest_completed_sprint_of_the_user()
    {
        $user = User::factory()->create();
        $one = $this->track(1);
        $two = $this->track(2);
        $demo = Scenario::factory()->create(['sequence' => null]);
        $earlier = $this->play($user, $one, false, 40);
        $earlier->update(['completed_at' => now()->subDay()]);
        $latest = $this->play($user, $one, true, 82);
        $this->play($user, $demo);
        $this->play(User::factory()->create(), $two, true, 99);
        $this->actingAs($user);

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('scenarios.0.result', ['overall' => 82, 'passed' => true, 'completed_at' => $latest->completed_at->toIso8601String()])
            ->where('scenarios.1.result', null)
            ->where('scenarios.2.result.overall', null)
            ->where('scenarios.2.result.passed', null));
    }

    public function test_an_offer_has_only_the_listed_fields_and_no_secrets()
    {
        Scenario::factory()->create(['sequence' => 1, 'briefing' => 'SECRET BRIEFING', 'colleague_notes' => ['sarah' => 'SECRET NOTE']]);
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('scenarios.0', fn ($offer) => array_keys($offer->toArray()) === ['id', 'title', 'summary', 'level', 'duration_days', 'sequence', 'is_demo', 'result']));

        $this->assertStringNotContainsString('SECRET', $response->getContent());
    }

    public function test_the_offers_are_not_loaded_when_there_is_an_active_sprint()
    {
        $sprint = $this->startDemoSprint();
        $this->actingAs($sprint->user);

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('scenarios', [])
            ->where('recommended_id', null));
    }

    public function test_the_shared_props_describe_the_current_sprint_and_unread_messages()
    {
        $sprint = $this->startDemoSprint();
        $unread = $sprint->messages()->whereNull('read_at')->count();
        $this->assertGreaterThan(0, $unread);

        $this->actingAs($sprint->user);

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('sprint.id', $sprint->id)
                ->where('sprint.status', 'active')
                ->where('sprint.scenario_title', 'Demo sprint')
                ->where('sprint.current_day', 1)
                ->where('sprint.duration_days', 2)
                ->where('sprint.deadline_at', $sprint->deadline_at->toIso8601String())
                ->where('sprint.is_overdue', false)
                ->where('unreadMessages', $unread));
    }

    public function test_the_shared_props_are_empty_without_a_sprint()
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('sprint', null)
                ->where('unreadMessages', 0));
    }

    public function test_a_late_active_sprint_is_overdue_but_a_paused_one_is_not()
    {
        $sprint = $this->startDemoSprint();
        $sprint->update(['deadline_at' => now()->subHour()]);
        $this->actingAs($sprint->user);

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('sprint.is_overdue', true));

        $sprint->update(['status' => 'paused', 'paused_at' => now()]);

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('sprint.status', 'paused')
            ->where('sprint.is_overdue', false));
    }

    public function test_a_completed_sprint_without_an_evaluation_reports_none_yet()
    {
        $sprint = $this->completedSprint();
        $this->actingAs($sprint->user);

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('completed.evaluation', null));
    }

    public function test_the_completed_sprint_reports_the_state_of_its_evaluation()
    {
        $sprint = $this->completedSprint();
        $this->actingAs($sprint->user);
        $url = route('sprints.evaluation.show', $sprint, false);

        $evaluation = SprintEvaluation::factory()->create(['sprint_id' => $sprint->id]);
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('completed.evaluation', ['status' => 'preparing', 'overall' => null, 'passed' => null, 'url' => $url]));

        $evaluation->update(['status' => 'awaiting_answers']);
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('completed.evaluation.status', 'awaiting_answers'));

        $evaluation->update(['status' => 'writing']);
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('completed.evaluation.status', 'writing'));

        $evaluation->update(['status' => 'completed', 'overall' => 82, 'passed' => true]);
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('completed.evaluation', ['status' => 'completed', 'overall' => 82, 'passed' => true, 'url' => $url]));
    }

    public function test_the_progress_is_passed_in_every_state()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('progress', ['level' => 'Junior', 'passed' => 0, 'needed' => 3, 'next' => 'Medior']));

        SprintEvaluation::factory()->completed()->create(['sprint_id' => $this->completedSprint($user)->id]);

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('progress.passed', 1));

        $this->startDemoSprint($user);

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('progress.passed', 1)
            ->where('progress.next', 'Medior'));
    }

    public function test_the_shared_user_has_a_level()
    {
        $this->actingAs(User::factory()->create(['level' => 'medior']));

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.level', 'medior')
            ->where('auth.user.level_label', 'Medior'));
    }
}
