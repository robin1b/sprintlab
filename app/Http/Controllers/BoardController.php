<?php

namespace App\Http\Controllers;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Services\Sprint\SprintService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class BoardController extends Controller
{
    // Words that add nothing to a branch name.
    private const SKIPPED_WORDS = ['a', 'an', 'the', 'can', 'be', 'to', 'of', 'is', 'by'];

    private const BRANCH_MAX_LENGTH = 50;

    public function __construct(private readonly SprintService $sprints) {}

    public function __invoke(Request $request): Response
    {
        $sprint = $this->sprints->currentFor($request->user());

        $tickets = $sprint === null ? [] : $sprint->tickets()
            ->whereNotNull('released_at')
            ->with('pullRequest.repository')
            ->orderBy('day')->orderBy('sort')->orderBy('id')
            ->get()
            ->map(fn (Ticket $ticket) => $this->present($ticket))
            ->all();

        return Inertia::render('Board', [
            'tickets' => $tickets,
            'statuses' => collect(TicketStatus::cases())->map(fn (TicketStatus $status) => [
                'value' => $status->value,
                'label' => $status->label(),
            ])->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Ticket $ticket): array
    {
        $pullRequest = $ticket->pullRequest;

        return [
            'id' => $ticket->id,
            'key' => $ticket->key,
            'title' => $ticket->title,
            'type' => $ticket->type->value,
            'status' => $ticket->status->value,
            'day' => $ticket->day,
            'description' => $ticket->description,
            'acceptance_criteria' => $ticket->acceptance_criteria,
            'reporter' => [
                'key' => $ticket->reporter->value,
                'name' => $ticket->reporter->displayName(),
                'role' => $ticket->reporter->role(),
            ],
            'pull_request' => $pullRequest === null ? null : [
                'number' => $pullRequest->number,
                'url' => 'https://github.com/'.$pullRequest->repository->full_name.'/pull/'.$pullRequest->number,
            ],
            'suggested_branch' => $this->branchName($ticket),
        ];
    }

    private function branchName(Ticket $ticket): string
    {
        $words = array_filter(
            explode('-', Str::slug($ticket->title)),
            fn (string $word) => $word !== '' && ! in_array($word, self::SKIPPED_WORDS, true),
        );

        $branch = strtolower($ticket->key);

        foreach ($words as $word) {
            if (strlen($branch.'-'.$word) > self::BRANCH_MAX_LENGTH) {
                break;
            }

            $branch .= '-'.$word;
        }

        return $branch;
    }
}
