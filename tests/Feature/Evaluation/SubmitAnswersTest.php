<?php

namespace Tests\Feature\Evaluation;

use App\Enums\EvaluationStatus;
use App\Jobs\WriteSprintEvaluation;
use App\Models\SprintEvaluation;
use App\Services\Evaluation\SprintEvaluationService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

class SubmitAnswersTest extends TestCase
{
    use RefreshDatabase;

    private function submit(SprintEvaluation $evaluation, array $answers): void
    {
        app(SprintEvaluationService::class)->submitAnswers($evaluation, $answers);
    }

    public function test_the_answers_are_trimmed_stored_and_the_writer_is_queued(): void
    {
        Queue::fake();
        $evaluation = SprintEvaluation::factory()->awaitingAnswers()->create();

        $this->submit($evaluation, ['  First  ', "Second\n", 'Third']);

        $evaluation->refresh();
        $this->assertSame(EvaluationStatus::Writing, $evaluation->status);
        $this->assertSame(['First', 'Second', 'Third'], $evaluation->answers);
        Queue::assertPushed(WriteSprintEvaluation::class, fn (WriteSprintEvaluation $job) => $job->evaluationId === $evaluation->id);
    }

    public function test_the_answer_keys_do_not_matter(): void
    {
        Queue::fake();
        $evaluation = SprintEvaluation::factory()->awaitingAnswers()->create();

        $this->submit($evaluation, ['a' => 'One', 'b' => 'Two', 'c' => 'Three']);

        $this->assertSame(['One', 'Two', 'Three'], $evaluation->refresh()->answers);
    }

    public function test_it_only_works_while_awaiting_answers(): void
    {
        Queue::fake();

        foreach ([EvaluationStatus::Preparing, EvaluationStatus::Writing, EvaluationStatus::Completed, EvaluationStatus::Failed] as $status) {
            $evaluation = SprintEvaluation::factory()->awaitingAnswers()->status($status)->create();

            try {
                $this->submit($evaluation, ['One', 'Two', 'Three']);
                $this->fail("A {$status->value} evaluation should not take answers.");
            } catch (DomainException) {
                $this->assertNull($evaluation->refresh()->answers);
            }
        }

        Queue::assertNothingPushed();
    }

    public function test_a_second_submission_is_refused(): void
    {
        Queue::fake();
        $evaluation = SprintEvaluation::factory()->awaitingAnswers()->create();
        $stale = SprintEvaluation::find($evaluation->id);

        $this->submit($evaluation, ['One', 'Two', 'Three']);

        // A second tab still holds the old status.
        $this->expectException(DomainException::class);
        $this->submit($stale, ['Other', 'Other', 'Other']);
    }

    public function test_the_number_of_answers_must_match_the_questions(): void
    {
        Queue::fake();
        $evaluation = SprintEvaluation::factory()->awaitingAnswers()->create();

        foreach ([[], ['One', 'Two'], ['One', 'Two', 'Three', 'Four']] as $answers) {
            try {
                $this->submit($evaluation, $answers);
                $this->fail('The wrong number of answers should be refused.');
            } catch (InvalidArgumentException) {
                $this->assertSame(EvaluationStatus::AwaitingAnswers, $evaluation->refresh()->status);
            }
        }

        Queue::assertNothingPushed();
    }

    public function test_answers_must_be_strings(): void
    {
        Queue::fake();
        $evaluation = SprintEvaluation::factory()->awaitingAnswers()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->submit($evaluation, ['One', 2, 'Three']);
    }

    public function test_an_answer_can_have_at_most_the_configured_length(): void
    {
        Queue::fake();
        config(['sprintlab.evaluation.answer_max_characters' => 10]);
        $evaluation = SprintEvaluation::factory()->awaitingAnswers()->create();

        // Exactly at the limit is fine, also with spaces around it that are trimmed away.
        $this->submit($evaluation, [str_repeat('é', 10), ' '.str_repeat('b', 10).' ', 'c']);
        $this->assertSame(EvaluationStatus::Writing, $evaluation->refresh()->status);

        $other = SprintEvaluation::factory()->awaitingAnswers()->create();
        $this->expectException(InvalidArgumentException::class);
        $this->submit($other, ['a', str_repeat('é', 11), 'c']);
    }

    public function test_an_empty_answer_is_accepted_and_will_be_rated_as_a_dodge(): void
    {
        Queue::fake();
        $evaluation = SprintEvaluation::factory()->awaitingAnswers()->create();

        $this->submit($evaluation, ['', 'Two', 'Three']);

        $this->assertSame(['', 'Two', 'Three'], $evaluation->refresh()->answers);
    }
}
