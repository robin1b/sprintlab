<?php

namespace App\Http\Controllers;

use App\Enums\Colleague;
use App\Enums\EvaluationStatus;
use App\Enums\SprintStatus;
use App\Enums\TicketStatus;
use App\Models\Scenario;
use App\Models\Sprint;
use App\Models\SprintEvaluation;
use App\Models\User;
use App\Services\Evaluation\SprintEvaluationService;
use App\Services\GitHub\Onboarding;
use App\Services\Sprint\SprintService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly SprintService $sprints,
        private readonly SprintEvaluationService $evaluations,
        private readonly Onboarding $onboarding,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $sprint = $this->sprints->currentFor($user);

        $offers = $sprint === null ? $this->offers($user) : ['scenarios' => [], 'recommended_id' => null];

        $completed = null;

        if ($sprint === null) {
            $last = $user->sprints()->latest('id')->with('scenario')->first();

            if ($last !== null && $last->status === SprintStatus::Completed) {
                $completed = [
                    'scenario_title' => $last->scenario->title,
                    'completed_at' => $last->completed_at?->toIso8601String(),
                    'evaluation' => $this->evaluationState($last),
                ];
            }
        }

        return Inertia::render('Dashboard', [
            'scenarios' => $offers['scenarios'],
            'recommended_id' => $offers['recommended_id'],
            'completed' => $completed,
            'setup' => $this->onboarding->stateFor($user),
            'progress' => $this->evaluations->progressFor($user),
            'overview' => $sprint === null ? null : $this->overview($sprint),
            'team' => collect(Colleague::cases())->map(fn (Colleague $colleague) => [
                'key' => $colleague->value,
                'name' => $colleague->displayName(),
                'role' => $colleague->role(),
            ])->all(),
        ]);
    }

    /**
     * The sprints this user can start: the track of their level first, then the demos and extras.
     *
     * @return array{scenarios: list<array<string, mixed>>, recommended_id: int|null}
     */
    private function offers(User $user): array
    {
        $track = Scenario::query()
            ->where('level', $user->level->value)
            ->whereNotNull('sequence')
            ->orderBy('sequence')->orderBy('id')
            ->get();

        $extras = Scenario::query()->whereNull('sequence')->orderBy('id')->get();

        // Loaded once. Newest first, so the first sprint per scenario is the latest one.
        $completedSprints = $user->sprints()
            ->where('status', SprintStatus::Completed)
            ->with('evaluation')
            ->orderByDesc('completed_at')->orderByDesc('id')
            ->get()
            ->groupBy('scenario_id');

        $offers = [];
        $passedIds = [];

        foreach ($track->concat($extras) as $scenario) {
            $played = $completedSprints->get($scenario->id);
            $latest = $played?->first();
            $evaluation = $latest?->evaluation;
            $finished = $evaluation !== null && $evaluation->status === EvaluationStatus::Completed;

            if ($played?->contains(fn (Sprint $done) => $done->evaluation?->status === EvaluationStatus::Completed && $done->evaluation->passed === true)) {
                $passedIds[$scenario->id] = true;
            }

            $offers[] = [
                'id' => $scenario->id,
                'title' => $scenario->title,
                'summary' => $scenario->summary,
                'level' => $scenario->level,
                'duration_days' => $scenario->duration_days,
                'sequence' => $scenario->sequence,
                'is_demo' => $scenario->sequence === null,
                'result' => $latest === null ? null : [
                    'overall' => $finished ? $evaluation->overall : null,
                    'passed' => $finished ? $evaluation->passed : null,
                    'completed_at' => $latest->completed_at?->toIso8601String(),
                ],
            ];
        }

        $collection = collect($offers);

        $recommended = $collection->first(fn (array $offer) => $offer['sequence'] !== null && ! isset($passedIds[$offer['id']]))
            ?? $collection->first(fn (array $offer) => $offer['result'] === null)
            ?? $collection->first();

        return [
            'scenarios' => $offers,
            'recommended_id' => $recommended['id'] ?? null,
        ];
    }

    /**
     * @return array{status: string, overall: int|null, passed: bool|null, url: string}|null
     */
    private function evaluationState(Sprint $sprint): ?array
    {
        $evaluation = SprintEvaluation::query()->where('sprint_id', $sprint->id)->first();

        if ($evaluation === null) {
            return null;
        }

        return [
            'status' => $evaluation->status->value,
            'overall' => $evaluation->overall,
            'passed' => $evaluation->passed,
            'url' => route('sprints.evaluation.show', $sprint, false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function overview(Sprint $sprint): array
    {
        $released = $sprint->tickets()->whereNotNull('released_at');
        $perStatus = (clone $released)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $counts = collect(TicketStatus::cases())->map(fn (TicketStatus $status) => [
            'value' => $status->value,
            'label' => $status->label(),
            'count' => (int) ($perStatus[$status->value] ?? 0),
        ])->all();

        $today = (clone $released)
            ->where('day', $sprint->current_day)
            ->orderBy('sort')->orderBy('id')
            ->get(['id', 'key', 'title', 'status'])
            ->map(fn ($ticket) => [
                'key' => $ticket->key,
                'title' => $ticket->title,
                'status' => $ticket->status->value,
            ])->all();

        $latest = $sprint->messages()
            ->where('from_user', false)
            ->orderByDesc('id')
            ->limit(3)
            ->get()
            ->map(fn ($message) => [
                'colleague' => $message->colleague->displayName(),
                'body' => Str::limit($message->body, 140),
                'created_at' => $message->created_at?->toIso8601String(),
            ])->all();

        return [
            'ticket_counts' => $counts,
            'todays_tickets' => $today,
            'latest_messages' => $latest,
        ];
    }
}
