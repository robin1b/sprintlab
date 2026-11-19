<?php

namespace App\Http\Controllers;

use App\Enums\Colleague;
use App\Enums\SprintStatus;
use App\Enums\TicketStatus;
use App\Models\Scenario;
use App\Models\Sprint;
use App\Services\Sprint\SprintService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(private readonly SprintService $sprints) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $sprint = $this->sprints->currentFor($user);

        $scenario = Scenario::query()->orderBy('id')->first();

        $completed = null;

        if ($sprint === null) {
            $last = $user->sprints()->latest('id')->with('scenario')->first();

            if ($last !== null && $last->status === SprintStatus::Completed) {
                $completed = [
                    'scenario_title' => $last->scenario->title,
                    'completed_at' => $last->completed_at?->toIso8601String(),
                ];
            }
        }

        return Inertia::render('Dashboard', [
            'scenario' => $scenario === null ? null : [
                'id' => $scenario->id,
                'title' => $scenario->title,
                'summary' => $scenario->summary,
                'level' => $scenario->level,
                'duration_days' => $scenario->duration_days,
            ],
            'completed' => $completed,
            'overview' => $sprint === null ? null : $this->overview($sprint),
            'team' => collect(Colleague::cases())->map(fn (Colleague $colleague) => [
                'key' => $colleague->value,
                'name' => $colleague->displayName(),
                'role' => $colleague->role(),
            ])->all(),
        ]);
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
