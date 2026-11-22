<?php

namespace Tests\Feature\Evaluation;

use App\Enums\Level;
use App\Enums\TicketStatus;
use App\Models\Message;
use App\Models\Scenario;
use App\Models\Sprint;
use App\Models\SprintEvaluation;
use App\Models\User;
use App\Services\Evaluation\SprintEvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\EvaluationFixtures;
use Tests\TestCase;

class PromotionTest extends TestCase
{
    use EvaluationFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // No AI: the evaluation finishes in one step on the PHP areas.
        config(['sprintlab.ai.enabled' => false, 'sprintlab.evaluation.sprints_to_promote' => 3]);
    }

    /**
     * Evaluate a sprint of the user that passes (a perfect one) or fails.
     */
    private function evaluate(User $user, bool $pass = true): SprintEvaluation
    {
        $sprint = $pass ? $this->sprintWithWork($user) : $this->completedSprint($user, inTime: false);

        if (! $pass) {
            // One unfinished ticket without a pull request: a low delivery and process score.
            $this->addTicket($sprint, status: TicketStatus::Todo);
        }

        return app(SprintEvaluationService::class)->prepare($sprint) ?? $this->fail('No evaluation.');
    }

    /**
     * Earlier passed evaluations at a level, written straight to the table.
     */
    private function passedBefore(User $user, int $count, Level $level, bool $passed = true): void
    {
        for ($i = 0; $i < $count; $i++) {
            SprintEvaluation::factory()->completed($passed, $level)->create([
                'sprint_id' => Sprint::factory()->completed()->for($user)->for(Scenario::factory()->state(['sequence' => $i + 1])),
            ]);
        }
    }

    public function test_a_demo_does_not_count_towards_a_promotion(): void
    {
        $user = User::factory()->create(['name' => 'Robin Broos']);
        $this->passedBefore($user, 2, Level::Junior);

        // A passed sprint of a scenario outside the track.
        SprintEvaluation::factory()->completed(true, Level::Junior)->create([
            'sprint_id' => Sprint::factory()->completed()->for($user)->for(Scenario::factory()->state(['sequence' => null])),
        ]);

        $this->assertSame(2, app(SprintEvaluationService::class)->progressFor($user)['passed']);
        $this->assertSame(Level::Junior, $user->refresh()->level);
    }

    public function test_passing_the_same_sprint_again_counts_once(): void
    {
        $user = User::factory()->create(['name' => 'Robin Broos']);
        $scenario = Scenario::factory()->create(['sequence' => 1]);

        foreach (range(1, 4) as $ignored) {
            SprintEvaluation::factory()->completed(true, Level::Junior)->create([
                'sprint_id' => Sprint::factory()->completed()->for($user)->for($scenario),
            ]);
        }

        $this->assertSame(1, app(SprintEvaluationService::class)->progressFor($user)['passed']);
    }

    public function test_the_user_is_promoted_after_the_configured_number_of_passed_sprints(): void
    {
        $user = User::factory()->create(['name' => 'Robin Broos']);

        $first = $this->evaluate($user);
        $second = $this->evaluate($user);

        $this->assertSame(Level::Junior, $user->refresh()->level);
        $this->assertNull($first->refresh()->promoted_to);
        $this->assertNull($second->refresh()->promoted_to);

        $third = $this->evaluate($user);

        $this->assertSame(Level::Medior, $user->refresh()->level);
        $this->assertSame('medior', $third->refresh()->promoted_to);
        $this->assertSame('junior', $third->level->value);
        $this->assertSame('Your evaluation is ready. You have been promoted to Medior. Well done.', Message::latest('id')->first()?->body);
    }

    public function test_only_one_message_is_sent_per_evaluation_also_when_promoted(): void
    {
        $user = User::factory()->create(['name' => 'Robin Broos']);
        $this->passedBefore($user, 2, Level::Junior);

        $evaluation = $this->evaluate($user);

        $this->assertSame('medior', $evaluation->refresh()->promoted_to);
        $this->assertSame(1, Message::where('sprint_id', $evaluation->sprint_id)->count());
    }

    public function test_failed_sprints_do_not_count(): void
    {
        $user = User::factory()->create(['name' => 'Robin Broos']);
        $this->passedBefore($user, 2, Level::Junior);
        $this->passedBefore($user, 3, Level::Junior, passed: false);

        $failed = $this->evaluate($user, pass: false);

        $this->assertFalse($failed->refresh()->passed);
        $this->assertSame(Level::Junior, $user->refresh()->level);
        $this->assertNull($failed->promoted_to);
        $this->assertSame('Your evaluation is ready.', Message::latest('id')->first()?->body);
    }

    public function test_sprints_of_another_level_or_user_do_not_count(): void
    {
        $user = User::factory()->create(['name' => 'Robin Broos']);
        $this->passedBefore($user, 1, Level::Junior);
        $this->passedBefore($user, 5, Level::Senior);
        $this->passedBefore(User::factory()->create(), 5, Level::Junior);

        $this->evaluate($user);

        $this->assertSame(Level::Junior, $user->refresh()->level);
    }

    public function test_the_count_starts_again_at_the_new_level(): void
    {
        $user = User::factory()->create(['name' => 'Robin Broos']);
        $this->passedBefore($user, 2, Level::Junior);
        $this->evaluate($user);
        $this->assertSame(Level::Medior, $user->refresh()->level);

        $this->evaluate($user);
        $this->evaluate($user);
        $this->assertSame(Level::Medior, $user->refresh()->level);

        $last = $this->evaluate($user);
        $this->assertSame(Level::Senior, $user->refresh()->level);
        $this->assertSame('senior', $last->refresh()->promoted_to);
    }

    public function test_a_senior_is_not_promoted_any_further(): void
    {
        $user = User::factory()->create(['name' => 'Robin Broos', 'level' => Level::Senior]);
        $this->passedBefore($user, 5, Level::Senior);

        $evaluation = $this->evaluate($user);

        $this->assertSame(Level::Senior, $user->refresh()->level);
        $this->assertNull($evaluation->refresh()->promoted_to);
        $this->assertSame('Your evaluation is ready.', Message::latest('id')->first()?->body);
    }

    public function test_the_evaluation_keeps_the_level_of_the_moment_it_was_prepared(): void
    {
        $user = User::factory()->create(['name' => 'Robin Broos', 'level' => Level::Medior]);

        $evaluation = $this->evaluate($user);

        $this->assertSame(Level::Medior, $evaluation->level);
    }

    public function test_progress_for_the_pages(): void
    {
        $user = User::factory()->create();
        $this->passedBefore($user, 2, Level::Junior);
        $this->passedBefore($user, 1, Level::Junior, passed: false);
        $service = app(SprintEvaluationService::class);

        $this->assertSame(['level' => 'Junior', 'passed' => 2, 'needed' => 3, 'next' => 'Medior'], $service->progressFor($user));

        $senior = User::factory()->create(['level' => Level::Senior]);
        $this->assertSame(['level' => 'Senior', 'passed' => 0, 'needed' => 3, 'next' => null], $service->progressFor($senior));
    }

    public function test_the_number_of_sprints_comes_from_the_config(): void
    {
        config(['sprintlab.evaluation.sprints_to_promote' => 1]);
        $user = User::factory()->create(['name' => 'Robin Broos']);

        $this->evaluate($user);

        $this->assertSame(Level::Medior, $user->refresh()->level);
    }
}
