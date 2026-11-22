<?php

namespace Database\Factories;

use App\Enums\EvaluationStatus;
use App\Enums\Level;
use App\Models\Sprint;
use App\Models\SprintEvaluation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<SprintEvaluation>
 */
class SprintEvaluationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sprint_id' => Sprint::factory()->completed(),
            'status' => EvaluationStatus::Preparing,
            'level' => Level::Junior,
        ];
    }

    public function status(EvaluationStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function awaitingAnswers(): static
    {
        return $this->state(fn () => [
            'status' => EvaluationStatus::AwaitingAnswers,
            'scores' => $this->emptyScores(['delivery' => 100]),
            'communication_evidence' => 'You asked Emily which endpoint to change.',
            'questions' => ['Why did you add the check there?', 'How did you handle the review remark?', 'What would you change?'],
        ]);
    }

    public function writing(): static
    {
        return $this->awaitingAnswers()->state(fn () => [
            'status' => EvaluationStatus::Writing,
            'answers' => ['Because of X.', 'I fixed it.', 'I would add a test.'],
        ]);
    }

    /**
     * A finished evaluation. Pass `passed: false` for a failed sprint.
     */
    public function completed(bool $passed = true, ?Level $level = null): static
    {
        return $this->state(fn () => [
            'status' => EvaluationStatus::Completed,
            'level' => $level ?? Level::Junior,
            'scores' => $this->emptyScores(['delivery' => $passed ? 90 : 30]),
            'overall' => $passed ? 90 : 30,
            'passed' => $passed,
            'completed_at' => Carbon::now(),
        ]);
    }

    public function failed(string $error = 'The model did not answer.'): static
    {
        return $this->state(fn () => ['status' => EvaluationStatus::Failed, 'error' => $error]);
    }

    /**
     * @param  array<string, int|null>  $given
     * @return array<string, int|null>
     */
    private function emptyScores(array $given): array
    {
        return array_replace([
            'delivery' => null, 'code_quality' => null, 'review' => null,
            'communication' => null, 'process' => null, 'understanding' => null,
        ], $given);
    }
}
