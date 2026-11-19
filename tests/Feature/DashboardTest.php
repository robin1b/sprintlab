<?php

namespace Tests\Feature;

use App\Models\Message;
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
                ->where('scenario', null)
                ->where('overview', null)
                ->where('completed', null)
                ->has('team', 5));
    }

    public function test_without_a_sprint_the_first_scenario_can_be_started()
    {
        $scenario = $this->demoScenario();
        $this->actingAs(User::factory()->create());

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('scenario.id', $scenario->id)
                ->where('scenario.title', 'Demo sprint')
                ->where('scenario.duration_days', 2)
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
                ->where('overview.ticket_counts.0.count', 2)
                ->where('overview.ticket_counts.3.count', 0)
                ->has('overview.todays_tickets', 2)
                ->where('overview.todays_tickets.0.key', 'WK-1')
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
                ->where('scenario.title', 'Demo sprint'));
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
