<?php

namespace Tests\Feature\Evaluation;

use App\Ai\Agents\EvaluationInterviewer;
use App\Enums\AiFeature;
use App\Enums\Colleague;
use App\Enums\EvaluationStatus;
use App\Jobs\PrepareSprintEvaluation;
use App\Models\AiCall;
use App\Models\Message;
use App\Models\Sprint;
use App\Models\SprintEvaluation;
use App\Models\User;
use App\Services\Evaluation\SprintEvaluationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Prompts\AgentPrompt;
use RuntimeException;
use Tests\Support\EvaluationFixtures;
use Tests\TestCase;

class PrepareEvaluationTest extends TestCase
{
    use EvaluationFixtures, RefreshDatabase;

    private function prepare(Sprint $sprint): ?SprintEvaluation
    {
        return app(SprintEvaluationService::class)->prepare($sprint);
    }

    public function test_an_unfinished_sprint_gets_no_evaluation(): void
    {
        $this->fakeInterviewer();
        $sprint = Sprint::factory()->create();

        $this->assertNull($this->prepare($sprint));
        $this->assertSame(0, SprintEvaluation::count());
        EvaluationInterviewer::assertNeverPrompted();
    }

    public function test_it_stores_the_scores_the_questions_and_the_evidence(): void
    {
        $sprint = $this->sprintWithWork();
        $this->fakeInterviewer();

        $evaluation = $this->prepare($sprint);

        $this->assertSame(EvaluationStatus::AwaitingAnswers, $evaluation?->status);
        $this->assertSame('junior', $evaluation->level->value);
        $this->assertSame($sprint->id, $evaluation->sprint_id);
        $this->assertSame(100, $evaluation->scores['delivery']);
        $this->assertSame(100, $evaluation->scores['code_quality']);
        $this->assertNull($evaluation->scores['review']);
        $this->assertSame(100, $evaluation->scores['process']);
        // Rating 4 is 75 points.
        $this->assertSame(75, $evaluation->scores['communication']);
        $this->assertNull($evaluation->scores['understanding']);
        $this->assertSame('You told Emily you were stuck on the lease endpoint.', $evaluation->communication_evidence);
        $this->assertCount(3, $evaluation->questions);
        $this->assertNull($evaluation->overall);
        $this->assertNull($evaluation->completed_at);
    }

    public function test_the_interviewer_gets_the_level_the_first_name_and_the_material(): void
    {
        $sprint = $this->sprintWithWork(User::factory()->create(['name' => 'Robin Broos']));
        $this->fakeInterviewer();

        $this->prepare($sprint);

        EvaluationInterviewer::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('Scenario: Lease approvals')
            && str_contains($prompt->agent->instructions(), 'A junior developer on your team, Robin, just finished a sprint.'));
    }

    public function test_sarah_sends_one_message_and_the_call_is_recorded(): void
    {
        $sprint = $this->sprintWithWork();
        $this->fakeInterviewer();

        $this->prepare($sprint);

        $message = Message::sole();
        $this->assertSame(Colleague::Sarah, $message->colleague);
        $this->assertFalse($message->from_user);
        $this->assertSame($sprint->id, $message->sprint_id);
        $this->assertSame('Your sprint is done, Robin. I have three questions for your evaluation. You will find them on your evaluation page.', $message->body);

        $call = AiCall::sole();
        $this->assertSame(AiFeature::Evaluation, $call->feature);
        $this->assertSame($sprint->id, $call->sprint_id);
        $this->assertSame($sprint->user_id, $call->user_id);
    }

    public function test_preparing_twice_changes_nothing(): void
    {
        $sprint = $this->sprintWithWork();
        $this->fakeInterviewer();

        $first = $this->prepare($sprint);
        $second = $this->prepare($sprint);

        $this->assertSame($first?->id, $second?->id);
        $this->assertSame(1, SprintEvaluation::count());
        $this->assertSame(1, Message::count());
        $this->assertSame(1, AiCall::count());
        EvaluationInterviewer::assertPromptedTimes(1);
    }

    public function test_the_rating_is_clamped_to_1_to_5(): void
    {
        $this->fakeInterviewer($this->interviewerAnswer(['communication' => 9]));
        $evaluation = $this->prepare($this->sprintWithWork());
        $this->assertSame(100, $evaluation?->scores['communication']);

        $this->fakeInterviewer($this->interviewerAnswer(['communication' => -3]));
        $evaluation = $this->prepare($this->sprintWithWork());
        $this->assertSame(0, $evaluation?->scores['communication']);
    }

    public function test_questions_are_trimmed_and_cut_to_exactly_three(): void
    {
        $this->fakeInterviewer($this->interviewerAnswer(['questions' => ['  One?  ', '', 'Two?', '   ', 'Three?', 'Four?']]));

        $evaluation = $this->prepare($this->sprintWithWork());

        $this->assertSame(EvaluationStatus::AwaitingAnswers, $evaluation?->status);
        $this->assertSame(['One?', 'Two?', 'Three?'], $evaluation->questions);
    }

    public function test_fewer_than_three_questions_finish_the_evaluation_without_them(): void
    {
        $this->fakeInterviewer($this->interviewerAnswer(['questions' => ['Only one?', '  ']]));

        $evaluation = $this->prepare($this->sprintWithWork());

        $this->assertSame(EvaluationStatus::Completed, $evaluation?->status);
        $this->assertNull($evaluation->questions);
        $this->assertNull($evaluation->scores['understanding']);
        // The communication rating was fine, so it still counts.
        $this->assertSame(75, $evaluation->scores['communication']);
        $this->assertNotNull($evaluation->overall);
        $this->assertSame('Your evaluation is ready.', Message::sole()->body);
    }

    public function test_with_the_ai_switched_off_it_finishes_at_once_on_the_php_areas(): void
    {
        config(['sprintlab.ai.enabled' => false]);
        $this->fakeInterviewer();

        $evaluation = $this->prepare($this->sprintWithWork());

        $this->assertSame(EvaluationStatus::Completed, $evaluation?->status);
        $this->assertNull($evaluation->questions);
        $this->assertNull($evaluation->scores['communication']);
        // Delivery, code quality and process are all 100; the other areas do not count.
        $this->assertSame(100, $evaluation->overall);
        $this->assertTrue($evaluation->passed);
        $this->assertNotNull($evaluation->completed_at);
        $this->assertSame('Your evaluation is ready.', Message::sole()->body);
        $this->assertSame(0, AiCall::count());
        EvaluationInterviewer::assertNeverPrompted();
    }

    public function test_with_the_monthly_budget_reached_it_finishes_at_once(): void
    {
        config(['sprintlab.ai.monthly_budget_usd' => 1]);
        AiCall::factory()->create(['cost_micros' => 1_000_000]);
        $this->fakeInterviewer();

        $evaluation = $this->prepare($this->sprintWithWork());

        $this->assertSame(EvaluationStatus::Completed, $evaluation?->status);
        $this->assertNull($evaluation->questions);
        $this->assertSame(1, AiCall::count());
        EvaluationInterviewer::assertNeverPrompted();
    }

    public function test_a_sprint_with_nothing_to_judge_and_no_ai_has_no_overall_and_does_not_pass(): void
    {
        config(['sprintlab.ai.enabled' => false]);

        $evaluation = $this->prepare($this->completedSprint());

        $this->assertSame(EvaluationStatus::Completed, $evaluation?->status);
        $this->assertNull($evaluation->overall);
        $this->assertFalse($evaluation->passed);
    }

    public function test_a_failing_model_marks_the_evaluation_failed_rethrows_and_the_retry_works(): void
    {
        $sprint = $this->sprintWithWork();
        EvaluationInterviewer::fake(fn () => throw new RuntimeException(str_repeat('x', 2000)));

        try {
            $this->prepare($sprint);
            $this->fail('The failing model should have thrown.');
        } catch (RuntimeException) {
            $evaluation = SprintEvaluation::sole();
            $this->assertSame(EvaluationStatus::Failed, $evaluation->status);
            $this->assertSame(1000, mb_strlen((string) $evaluation->error));
        }

        $this->assertSame(0, Message::count());

        $this->fakeInterviewer();
        $retried = $this->prepare($sprint);

        $this->assertSame(EvaluationStatus::AwaitingAnswers, $retried?->status);
        $this->assertNull($retried->error);
        $this->assertSame(1, SprintEvaluation::count());
        $this->assertSame(1, Message::count());
    }

    public function test_the_job_prepares_the_evaluation(): void
    {
        $sprint = $this->sprintWithWork();
        $this->fakeInterviewer();

        (new PrepareSprintEvaluation($sprint->id))->handle(app(SprintEvaluationService::class));

        $this->assertSame(EvaluationStatus::AwaitingAnswers, $sprint->evaluation()->first()?->status);
    }

    public function test_the_job_ignores_a_sprint_that_is_gone(): void
    {
        $this->fakeInterviewer();

        (new PrepareSprintEvaluation(999))->handle(app(SprintEvaluationService::class));

        $this->assertSame(0, SprintEvaluation::count());
    }

    public function test_the_job_retries_are_set(): void
    {
        $job = new PrepareSprintEvaluation(1);

        $this->assertSame(2, $job->tries);
        $this->assertSame(150, $job->timeout);
    }
}
