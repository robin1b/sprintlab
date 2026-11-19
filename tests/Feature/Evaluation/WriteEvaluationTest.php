<?php

namespace Tests\Feature\Evaluation;

use App\Ai\Agents\EvaluationWriter;
use App\Enums\AiFeature;
use App\Enums\Colleague;
use App\Enums\EvaluationStatus;
use App\Jobs\WriteSprintEvaluation;
use App\Models\AiCall;
use App\Models\Message;
use App\Models\Sprint;
use App\Models\SprintEvaluation;
use App\Models\User;
use App\Services\Evaluation\SprintEvaluationService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Prompts\AgentPrompt;
use RuntimeException;
use Tests\Support\EvaluationFixtures;
use Tests\TestCase;

class WriteEvaluationTest extends TestCase
{
    use EvaluationFixtures, RefreshDatabase;

    /**
     * An evaluation waiting in `writing`, with real work in its sprint.
     *
     * @param  array<string, mixed>  $scores
     */
    private function writing(array $scores = []): SprintEvaluation
    {
        $sprint = $this->sprintWithWork(User::factory()->create(['name' => 'Robin Broos']));

        return SprintEvaluation::factory()->writing()->create([
            'sprint_id' => $sprint->id,
            'scores' => array_replace([
                'delivery' => 100, 'code_quality' => 100, 'review' => null,
                'communication' => 75, 'process' => 100, 'understanding' => null,
            ], $scores),
        ]);
    }

    private function write(SprintEvaluation $evaluation): void
    {
        app(SprintEvaluationService::class)->write($evaluation);
    }

    public function test_it_stores_notes_understanding_texts_overall_and_passed(): void
    {
        config(['sprintlab.evaluation.weights' => [
            'delivery' => 20, 'code_quality' => 25, 'review' => 15,
            'communication' => 15, 'process' => 10, 'understanding' => 15,
        ]]);
        $evaluation = $this->writing();
        $this->fakeWriter();

        $this->write($evaluation);

        $evaluation->refresh();
        $this->assertSame(EvaluationStatus::Completed, $evaluation->status);
        $this->assertSame([
            ['understanding' => 5, 'note' => 'Accurate and with trade-offs.'],
            ['understanding' => 3, 'note' => 'Roughly right.'],
            ['understanding' => 1, 'note' => 'No real answer.'],
        ], $evaluation->answer_notes);
        // Ratings 5, 3 and 1 are 100, 50 and 0 points: 50 on average.
        $this->assertSame(50, $evaluation->scores['understanding']);
        $this->assertSame('You kept the fix small.', $evaluation->strengths);
        $this->assertSame('Write the test first.', $evaluation->growth);
        $this->assertSame('Add a test before you open the pull request.', $evaluation->advice);
        // (2000 + 2500 + 1125 + 1000 + 750) / 85
        $this->assertSame(87, $evaluation->overall);
        $this->assertTrue($evaluation->passed);
        $this->assertNotNull($evaluation->completed_at);
        $this->assertNull($evaluation->promoted_to);
    }

    public function test_a_low_overall_does_not_pass(): void
    {
        $evaluation = $this->writing(['delivery' => 20, 'code_quality' => 40, 'communication' => 25, 'process' => 0]);
        $this->fakeWriter($this->writerAnswer(['answers' => [
            ['index' => 1, 'understanding' => 1, 'note' => 'a'],
            ['index' => 2, 'understanding' => 1, 'note' => 'b'],
            ['index' => 3, 'understanding' => 1, 'note' => 'c'],
        ]]));

        $this->write($evaluation);

        $evaluation->refresh();
        $this->assertSame(0, $evaluation->scores['understanding']);
        $this->assertLessThan(70, $evaluation->overall);
        $this->assertFalse($evaluation->passed);
    }

    public function test_sarah_sends_one_message_and_the_call_is_recorded(): void
    {
        $evaluation = $this->writing();
        $this->fakeWriter();

        $this->write($evaluation);

        $message = Message::sole();
        $this->assertSame(Colleague::Sarah, $message->colleague);
        $this->assertFalse($message->from_user);
        $this->assertSame($evaluation->sprint_id, $message->sprint_id);
        $this->assertSame('Your evaluation is ready.', $message->body);

        $call = AiCall::sole();
        $this->assertSame(AiFeature::Evaluation, $call->feature);
        $this->assertSame($evaluation->sprint_id, $call->sprint_id);
    }

    public function test_the_writer_gets_the_questions_the_answers_and_the_name(): void
    {
        $evaluation = $this->writing();
        $this->fakeWriter();

        $this->write($evaluation);

        EvaluationWriter::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('Question 1: Why did you add the check there?')
            && $prompt->contains('Answer 3: I would add a test.')
            && str_contains($prompt->agent->instructions(), 'You held an evaluation conversation with Robin, a junior developer'));
    }

    public function test_a_missing_answer_rating_counts_as_1(): void
    {
        $evaluation = $this->writing();
        $this->fakeWriter($this->writerAnswer(['answers' => [
            ['index' => 1, 'understanding' => 5, 'note' => 'Good.'],
            ['index' => 3, 'understanding' => 5, 'note' => 'Good.'],
        ]]));

        $this->write($evaluation);

        $evaluation->refresh();
        $this->assertSame([5, 1, 5], array_column($evaluation->answer_notes, 'understanding'));
        $this->assertSame('', $evaluation->answer_notes[1]['note']);
        // 100 + 0 + 100 over three answers
        $this->assertSame(67, $evaluation->scores['understanding']);
    }

    public function test_ratings_are_clamped_and_an_unknown_index_is_ignored(): void
    {
        $evaluation = $this->writing();
        $this->fakeWriter($this->writerAnswer(['answers' => [
            ['index' => 1, 'understanding' => 9, 'note' => ' Fine. '],
            ['index' => 2, 'understanding' => -2, 'note' => 'No.'],
            ['index' => 7, 'understanding' => 5, 'note' => 'Nothing to do with a question.'],
        ]]));

        $this->write($evaluation);

        $evaluation->refresh();
        $this->assertSame([5, 1, 1], array_column($evaluation->answer_notes, 'understanding'));
        $this->assertSame('Fine.', $evaluation->answer_notes[0]['note']);
    }

    public function test_a_model_that_counts_from_zero_is_understood(): void
    {
        $evaluation = $this->writing();
        $this->fakeWriter($this->writerAnswer(['answers' => [
            ['index' => 0, 'understanding' => 5, 'note' => 'a'],
            ['index' => 1, 'understanding' => 4, 'note' => 'b'],
            ['index' => 2, 'understanding' => 3, 'note' => 'c'],
        ]]));

        $this->write($evaluation);

        $this->assertSame([5, 4, 3], array_column($evaluation->refresh()->answer_notes, 'understanding'));
    }

    public function test_with_the_ai_switched_off_it_finishes_without_understanding_and_texts(): void
    {
        config(['sprintlab.ai.enabled' => false]);
        $evaluation = $this->writing();
        $this->fakeWriter();

        $this->write($evaluation);

        $evaluation->refresh();
        $this->assertSame(EvaluationStatus::Completed, $evaluation->status);
        $this->assertNull($evaluation->scores['understanding']);
        $this->assertNull($evaluation->answer_notes);
        $this->assertNull($evaluation->strengths);
        $this->assertNotNull($evaluation->overall);
        $this->assertSame('Your evaluation is ready.', Message::sole()->body);
        EvaluationWriter::assertNeverPrompted();
    }

    public function test_with_the_budget_used_up_it_finishes_without_understanding_and_texts(): void
    {
        config(['sprintlab.ai.monthly_budget_usd' => 1]);
        AiCall::factory()->create(['cost_micros' => 2_000_000]);
        $evaluation = $this->writing();
        $this->fakeWriter();

        $this->write($evaluation);

        $evaluation->refresh();
        $this->assertSame(EvaluationStatus::Completed, $evaluation->status);
        $this->assertNull($evaluation->scores['understanding']);
        $this->assertNull($evaluation->growth);
        EvaluationWriter::assertNeverPrompted();
    }

    public function test_a_failing_model_marks_the_evaluation_failed_rethrows_and_the_retry_works(): void
    {
        $evaluation = $this->writing();
        EvaluationWriter::fake(fn () => throw new RuntimeException('The model is down.'));

        try {
            $this->write($evaluation);
            $this->fail('The failing model should have thrown.');
        } catch (RuntimeException) {
            $evaluation->refresh();
            $this->assertSame(EvaluationStatus::Failed, $evaluation->status);
            $this->assertSame('The model is down.', $evaluation->error);
            $this->assertCount(3, $evaluation->answers);
        }

        $this->assertSame(0, Message::count());

        $this->fakeWriter();
        $this->write($evaluation);

        $evaluation->refresh();
        $this->assertSame(EvaluationStatus::Completed, $evaluation->status);
        $this->assertNull($evaluation->error);
        $this->assertSame(1, Message::count());
    }

    public function test_it_only_runs_from_writing_or_failed_with_answers(): void
    {
        $this->fakeWriter();

        foreach ([
            SprintEvaluation::factory()->awaitingAnswers()->create(),
            SprintEvaluation::factory()->writing()->completed()->create(),
            SprintEvaluation::factory()->failed()->create(),
        ] as $evaluation) {
            try {
                $this->write($evaluation);
                $this->fail('This evaluation should not be written.');
            } catch (DomainException) {
                $this->assertNotSame(EvaluationStatus::Writing, $evaluation->refresh()->status);
            }
        }

        EvaluationWriter::assertNeverPrompted();
    }

    public function test_the_job_writes_and_ignores_finished_evaluations(): void
    {
        $evaluation = $this->writing();
        $this->fakeWriter();
        $service = app(SprintEvaluationService::class);

        (new WriteSprintEvaluation($evaluation->id))->handle($service);
        (new WriteSprintEvaluation($evaluation->id))->handle($service);
        (new WriteSprintEvaluation(999))->handle($service);

        $this->assertSame(EvaluationStatus::Completed, $evaluation->refresh()->status);
        EvaluationWriter::assertPromptedTimes(1);
        $this->assertSame(1, Message::count());
    }

    public function test_the_job_retries_are_set(): void
    {
        $job = new WriteSprintEvaluation(1);

        $this->assertSame(2, $job->tries);
        $this->assertSame(150, $job->timeout);
    }

    public function test_submitting_runs_the_whole_flow_with_a_sync_queue(): void
    {
        $sprint = $this->sprintWithWork();
        $this->fakeInterviewer();
        $this->fakeWriter();
        $service = app(SprintEvaluationService::class);

        $evaluation = $service->prepare($sprint);
        $service->submitAnswers($evaluation, ['One', 'Two', 'Three']);

        $evaluation->refresh();
        $this->assertSame(EvaluationStatus::Completed, $evaluation->status);
        $this->assertSame(['One', 'Two', 'Three'], $evaluation->answers);
        $this->assertSame(2, Message::count());
        $this->assertSame(2, AiCall::count());
        $this->assertInstanceOf(Sprint::class, $evaluation->sprint);
    }
}
