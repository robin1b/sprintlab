<?php

namespace App\Http\Controllers;

use App\Enums\Level;
use App\Http\Requests\Sprint\StoreEvaluationAnswersRequest;
use App\Models\Sprint;
use App\Models\SprintEvaluation;
use App\Services\Evaluation\ScoreCalculator;
use App\Services\Evaluation\SprintEvaluationService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class SprintEvaluationController extends Controller
{
    public function __construct(private readonly SprintEvaluationService $evaluations) {}

    private const LABELS = [
        'delivery' => 'Delivery',
        'code_quality' => 'Code quality',
        'review' => 'Your reviews',
        'communication' => 'Communication',
        'process' => 'Way of working',
        'understanding' => 'Understanding',
    ];

    public function show(Request $request, Sprint $sprint): Response
    {
        Gate::authorize('evaluate', $sprint);
        abort_unless($sprint->isCompleted(), 404);

        $evaluation = SprintEvaluation::query()->where('sprint_id', $sprint->id)->first();
        $weights = (array) config('sprintlab.evaluation.weights');

        return Inertia::render('sprints/Evaluation', [
            // Not `sprint`: that name is the shared prop for the CURRENT sprint.
            'evaluated_sprint' => [
                'id' => $sprint->id,
                'scenario_title' => $sprint->scenario->title,
                'completed_at' => $sprint->completed_at?->toIso8601String(),
            ],
            'evaluation' => $evaluation === null ? null : $this->evaluationProps($evaluation),
            'areas' => array_map(fn (string $key): array => [
                'key' => $key,
                'label' => self::LABELS[$key],
                'weight' => (int) ($weights[$key] ?? 0),
            ], ScoreCalculator::AREAS),
            'pass_score' => (int) config('sprintlab.evaluation.pass_score'),
            'answer_max_characters' => (int) config('sprintlab.evaluation.answer_max_characters'),
            'progress' => $this->evaluations->progressFor($request->user()),
        ]);
    }

    public function storeAnswers(StoreEvaluationAnswersRequest $request, Sprint $sprint): RedirectResponse
    {
        $evaluation = SprintEvaluation::query()->where('sprint_id', $sprint->id)->first();

        if ($evaluation === null) {
            return back()->with('error', 'Your evaluation is not ready yet.');
        }

        try {
            $this->evaluations->submitAnswers($evaluation, $request->validated('answers'));
        } catch (DomainException|InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return to_route('sprints.evaluation.show', $sprint);
    }

    /**
     * @return array<string, mixed>
     */
    private function evaluationProps(SprintEvaluation $evaluation): array
    {
        return [
            'status' => $evaluation->status->value,
            'level' => $evaluation->level->value,
            'questions' => $evaluation->questions,
            'answers' => $evaluation->answers,
            'answer_notes' => $evaluation->answer_notes,
            'scores' => $evaluation->scores,
            'overall' => $evaluation->overall,
            'passed' => $evaluation->passed,
            'communication_evidence' => $evaluation->communication_evidence,
            'strengths' => $evaluation->strengths,
            'growth' => $evaluation->growth,
            'advice' => $evaluation->advice,
            'promoted_to' => $evaluation->promoted_to === null ? null : Level::from($evaluation->promoted_to)->label(),
            'completed_at' => $evaluation->completed_at?->toIso8601String(),
        ];
    }
}
