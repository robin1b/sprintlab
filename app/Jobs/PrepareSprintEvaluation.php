<?php

namespace App\Jobs;

use App\Models\Sprint;
use App\Services\Evaluation\SprintEvaluationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PrepareSprintEvaluation implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 150;

    public function __construct(public int $sprintId) {}

    public function handle(SprintEvaluationService $evaluations): void
    {
        $sprint = Sprint::find($this->sprintId);

        if ($sprint === null) {
            return;
        }

        $evaluations->prepare($sprint);
    }
}
