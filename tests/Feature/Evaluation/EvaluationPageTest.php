<?php

namespace Tests\Feature\Evaluation;

use App\Enums\EvaluationStatus;
use App\Enums\Level;
use App\Jobs\WriteSprintEvaluation;
use App\Models\Sprint;
use App\Models\SprintEvaluation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EvaluationFixtures;
use Tests\TestCase;

class EvaluationPageTest extends TestCase
{
    use EvaluationFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function evaluationFor(Sprint $sprint, string $state = 'awaitingAnswers', mixed ...$arguments): SprintEvaluation
    {
        return SprintEvaluation::factory()->{$state}(...$arguments)->create(['sprint_id' => $sprint->id]);
    }

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $sprint = $this->completedSprint();

        $this->get(route('sprints.evaluation.show', $sprint))->assertRedirect(route('login'));
        $this->post(route('sprints.evaluation.answers', $sprint))->assertRedirect(route('login'));
    }

    public function test_another_users_sprint_is_forbidden(): void
    {
        $sprint = $this->completedSprint();
        $this->evaluationFor($sprint);
        $this->actingAs(User::factory()->create());

        $this->get(route('sprints.evaluation.show', $sprint))->assertForbidden();
        $this->post(route('sprints.evaluation.answers', $sprint), ['answers' => ['a', 'b', 'c']])->assertForbidden();
    }

    public function test_a_sprint_that_is_not_completed_says_so_and_cannot_be_answered(): void
    {
        $sprint = Sprint::factory()->create();
        $this->actingAs($sprint->user);

        $this->get(route('sprints.evaluation.show', $sprint))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sprints/Evaluation')
                ->where('finished', false)
                ->where('evaluation', null)
                ->where('evaluated_sprint.completed_at', null));
        $this->post(route('sprints.evaluation.answers', $sprint), ['answers' => ['a', 'b', 'c']])->assertNotFound();
    }

    public function test_without_an_evaluation_the_prop_is_null_and_the_areas_are_listed(): void
    {
        $sprint = $this->completedSprint();
        $this->actingAs($sprint->user);

        $this->get(route('sprints.evaluation.show', $sprint))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sprints/Evaluation')
                ->where('evaluated_sprint.id', $sprint->id)
                ->where('evaluated_sprint.scenario_title', 'Lease approvals')
                ->where('evaluation', null)
                ->has('areas', 6)
                ->where('areas.0', ['key' => 'delivery', 'label' => 'Delivery', 'weight' => 20])
                ->where('areas.2.label', 'Your reviews')
                ->where('areas.4.label', 'Way of working')
                ->where('pass_score', 70)
                ->where('answer_max_characters', 2000)
                ->where('progress', ['level' => 'Junior', 'passed' => 0, 'needed' => 3, 'next' => 'Medior']));
    }

    public function test_a_preparing_evaluation(): void
    {
        $sprint = $this->completedSprint();
        $this->evaluationFor($sprint, 'status', EvaluationStatus::Preparing);
        $this->actingAs($sprint->user);

        $this->get(route('sprints.evaluation.show', $sprint))
            ->assertInertia(fn (Assert $page) => $page
                ->where('evaluation.status', 'preparing')
                ->where('evaluation.questions', null)
                ->where('evaluation.answers', null)
                ->where('evaluation.promoted_to', null));
    }

    public function test_an_evaluation_awaiting_answers_has_the_questions(): void
    {
        $sprint = $this->completedSprint();
        $this->evaluationFor($sprint);
        $this->actingAs($sprint->user);

        $this->get(route('sprints.evaluation.show', $sprint))
            ->assertInertia(fn (Assert $page) => $page
                ->where('evaluation.status', 'awaiting_answers')
                ->has('evaluation.questions', 3)
                ->where('evaluation.answers', null));
    }

    public function test_a_writing_evaluation_has_the_answers(): void
    {
        $sprint = $this->completedSprint();
        $this->evaluationFor($sprint, 'writing');
        $this->actingAs($sprint->user);

        $this->get(route('sprints.evaluation.show', $sprint))
            ->assertInertia(fn (Assert $page) => $page
                ->where('evaluation.status', 'writing')
                ->where('evaluation.answers.0', 'Because of X.'));
    }

    public function test_a_completed_evaluation_with_all_parts(): void
    {
        $sprint = $this->completedSprint();
        SprintEvaluation::factory()->writing()->completed()->create([
            'sprint_id' => $sprint->id,
            'scores' => ['delivery' => 90, 'code_quality' => 80, 'review' => null, 'communication' => 100, 'process' => 70, 'understanding' => 60],
            'communication_evidence' => 'You asked early.',
            'answer_notes' => [
                ['understanding' => 5, 'note' => 'Accurate.'],
                ['understanding' => 3, 'note' => 'Roughly right.'],
                ['understanding' => 1, 'note' => 'No answer.'],
            ],
            'strengths' => 'Small fix.',
            'growth' => 'Test first.',
            'advice' => 'Add a test.',
            'promoted_to' => Level::Medior->value,
        ]);
        $this->actingAs($sprint->user);

        $this->get(route('sprints.evaluation.show', $sprint))
            ->assertInertia(fn (Assert $page) => $page
                ->where('evaluation.status', 'completed')
                ->where('evaluation.level', 'junior')
                ->where('evaluation.overall', 90)
                ->where('evaluation.passed', true)
                ->where('evaluation.scores.review', null)
                ->where('evaluation.scores.delivery', 90)
                ->where('evaluation.communication_evidence', 'You asked early.')
                ->where('evaluation.strengths', 'Small fix.')
                ->where('evaluation.growth', 'Test first.')
                ->where('evaluation.advice', 'Add a test.')
                ->where('evaluation.answer_notes.0.understanding', 5)
                ->where('evaluation.promoted_to', 'Medior')
                ->whereType('evaluation.completed_at', 'string'));
    }

    public function test_a_completed_evaluation_without_ai_parts(): void
    {
        $sprint = $this->completedSprint();
        $this->evaluationFor($sprint, 'completed', false);
        $this->actingAs($sprint->user);

        $this->get(route('sprints.evaluation.show', $sprint))
            ->assertInertia(fn (Assert $page) => $page
                ->where('evaluation.status', 'completed')
                ->where('evaluation.passed', false)
                ->where('evaluation.questions', null)
                ->where('evaluation.answer_notes', null)
                ->where('evaluation.strengths', null)
                ->where('evaluation.advice', null)
                ->where('evaluation.communication_evidence', null));
    }

    public function test_a_failed_evaluation_does_not_show_the_error(): void
    {
        $sprint = $this->completedSprint();
        $this->evaluationFor($sprint, 'failed', 'secret stack trace');
        $this->actingAs($sprint->user);

        $this->get(route('sprints.evaluation.show', $sprint))
            ->assertInertia(fn (Assert $page) => $page
                ->where('evaluation.status', 'failed')
                ->missing('evaluation.error'));
    }

    public function test_submitting_the_answers_stores_them_and_queues_the_writer(): void
    {
        Queue::fake();
        $sprint = $this->completedSprint();
        $evaluation = $this->evaluationFor($sprint);
        $this->actingAs($sprint->user);

        $this->post(route('sprints.evaluation.answers', $sprint), ['answers' => ['  One ', 'Two', 'Three']])
            ->assertRedirect(route('sprints.evaluation.show', $sprint))
            ->assertSessionHasNoErrors();

        $evaluation->refresh();
        $this->assertSame(EvaluationStatus::Writing, $evaluation->status);
        $this->assertSame(['One', 'Two', 'Three'], $evaluation->answers);
        Queue::assertPushed(WriteSprintEvaluation::class, fn (WriteSprintEvaluation $job) => $job->evaluationId === $evaluation->id);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidAnswers(): array
    {
        return [
            'missing' => [null],
            'not an array' => ['text'],
            'one missing' => [['One', 'Two']],
            'too many' => [['One', 'Two', 'Three', 'Four']],
            'one empty' => [['One', '', 'Three']],
            'only spaces' => [['One', '   ', 'Three']],
            'too long' => [['One', 'Two', str_repeat('a', 2001)]],
            'not text' => [['One', ['nested'], 'Three']],
        ];
    }

    #[DataProvider('invalidAnswers')]
    public function test_invalid_answers_are_rejected_and_nothing_is_stored(mixed $answers): void
    {
        Queue::fake();
        $sprint = $this->completedSprint();
        $evaluation = $this->evaluationFor($sprint);
        $this->actingAs($sprint->user);

        $this->post(route('sprints.evaluation.answers', $sprint), $answers === null ? [] : ['answers' => $answers])
            ->assertSessionHasErrors();

        $this->assertSame(EvaluationStatus::AwaitingAnswers, $evaluation->refresh()->status);
        $this->assertNull($evaluation->answers);
        Queue::assertNothingPushed();
    }

    public function test_the_error_is_given_per_answer(): void
    {
        $sprint = $this->completedSprint();
        $this->evaluationFor($sprint);
        $this->actingAs($sprint->user);

        $this->post(route('sprints.evaluation.answers', $sprint), ['answers' => ['One', '', str_repeat('a', 2001)]])
            ->assertSessionHasErrors(['answers.1', 'answers.2'])
            ->assertSessionDoesntHaveErrors(['answers.0']);
    }

    public function test_an_answer_of_exactly_the_maximum_is_accepted(): void
    {
        Queue::fake();
        $sprint = $this->completedSprint();
        $this->evaluationFor($sprint);
        $this->actingAs($sprint->user);

        $this->post(route('sprints.evaluation.answers', $sprint), ['answers' => ['One', 'Two', str_repeat('a', 2000)]])
            ->assertSessionHasNoErrors();
    }

    public function test_a_second_submit_gives_the_flash_error(): void
    {
        Queue::fake();
        $sprint = $this->completedSprint();
        $this->evaluationFor($sprint);
        $this->actingAs($sprint->user);
        $answers = ['answers' => ['One', 'Two', 'Three']];

        $this->post(route('sprints.evaluation.answers', $sprint), $answers)->assertSessionMissing('error');
        $this->from(route('sprints.evaluation.show', $sprint))
            ->post(route('sprints.evaluation.answers', $sprint), ['answers' => ['Other', 'Other', 'Other']])
            ->assertRedirect(route('sprints.evaluation.show', $sprint))
            ->assertSessionHas('error');

        Queue::assertPushed(WriteSprintEvaluation::class, 1);
    }

    public function test_answers_without_an_evaluation_give_the_flash_error(): void
    {
        $sprint = $this->completedSprint();
        $this->actingAs($sprint->user);

        $this->post(route('sprints.evaluation.answers', $sprint), ['answers' => ['One', 'Two', 'Three']])
            ->assertSessionHas('error');
    }
}
