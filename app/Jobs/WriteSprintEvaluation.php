<?php

namespace App\Jobs;

use App\Enums\EvaluationStatus;
use App\Models\SprintEvaluation;
use App\Services\Evaluation\SprintEvaluationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class WriteSprintEvaluation implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 150;

    public function __construct(public int $evaluationId) {}

    public function handle(SprintEvaluationService $evaluations): void
    {
        $evaluation = SprintEvaluation::find($this->evaluationId);

        // A duplicate job for an evaluation that is already done does nothing.
        if ($evaluation === null || ! in_array($evaluation->status, [EvaluationStatus::Writing, EvaluationStatus::Failed], true)) {
            return;
        }

        $evaluations->write($evaluation);
    }
}
