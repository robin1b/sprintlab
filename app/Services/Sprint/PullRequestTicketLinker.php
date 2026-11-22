<?php

namespace App\Services\Sprint;

use App\Enums\PullRequestState;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\PullRequest;
use App\Models\Ticket;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;

/**
 * Connects a pull request to the sprint ticket it is about and keeps the
 * ticket status in step with it.
 */
class PullRequestTicketLinker
{
    // Pull request actions that mean "this is ready to be reviewed".
    private const READY_ACTIONS = ['opened', 'reopened', 'ready_for_review'];

    public function __construct(
        private readonly SprintService $sprints,
        private readonly TicketService $tickets,
    ) {}

    public function link(PullRequest $pullRequest, string $action): void
    {
        $userId = $pullRequest->repository->installation->user_id;
        $user = $userId === null ? null : User::find($userId);
        $sprint = $user === null ? null : $this->sprints->currentFor($user);

        if ($sprint === null) {
            return;
        }

        $ticket = $this->findTicket(
            $sprint->tickets()->whereNotNull('released_at')->orderBy('sort')->orderBy('id')->get(),
            $pullRequest,
        );

        if ($ticket === null) {
            return;
        }

        $ticket->update(['pull_request_id' => $pullRequest->id]);

        // The colleague opens the pull request of a review ticket, and the user
        // reviews it. Opening it must not move the ticket: only a good review does.
        if ($ticket->type === TicketType::Review) {
            return;
        }

        $to = $this->targetStatus($ticket, $pullRequest, $action);

        if ($to === null) {
            return;
        }

        try {
            $this->tickets->move($ticket, $to);
        } catch (DomainException) {
            // The sprint is paused: the link stays, the status waits.
        }
    }

    /**
     * The user pushed a new branch. When its name holds the key of a ticket
     * that is still "to do", the ticket moves to "in progress", like a board
     * that follows the repository.
     */
    public function branchCreated(User $user, string $branch): void
    {
        $sprint = $this->sprints->currentFor($user);

        if ($sprint === null) {
            return;
        }

        $ticket = $this->findTicketIn(
            $sprint->tickets()->whereNotNull('released_at')->orderBy('sort')->orderBy('id')->get(),
            $branch,
        );

        // A review ticket is about a colleague's branch, never about the user's own.
        if ($ticket === null || $ticket->type === TicketType::Review || $ticket->status !== TicketStatus::Todo) {
            return;
        }

        try {
            $this->tickets->move($ticket, TicketStatus::InProgress);
        } catch (DomainException) {
            // The sprint is paused: the ticket waits.
        }
    }

    /**
     * @param  Collection<int, Ticket>  $tickets
     */
    private function findTicket(Collection $tickets, PullRequest $pullRequest): ?Ticket
    {
        return $this->findTicketIn($tickets, $pullRequest->head_ref.' '.$pullRequest->title);
    }

    /**
     * @param  Collection<int, Ticket>  $tickets
     */
    private function findTicketIn(Collection $tickets, string $text): ?Ticket
    {
        return $tickets->first(function (Ticket $ticket) use ($text): bool {
            // The key must stand alone, so WK-1 does not match WK-10.
            $pattern = '/(?<![A-Za-z0-9])'.preg_quote($ticket->key, '/').'(?![0-9])/i';

            return preg_match($pattern, $text) === 1;
        });
    }

    private function targetStatus(Ticket $ticket, PullRequest $pullRequest, string $action): ?TicketStatus
    {
        if ($pullRequest->state === PullRequestState::Merged) {
            return TicketStatus::Done;
        }

        if ($pullRequest->state === PullRequestState::Closed) {
            return $ticket->status === TicketStatus::InReview ? TicketStatus::InProgress : null;
        }

        $readyForReview = in_array($action, self::READY_ACTIONS, true) && ! $pullRequest->draft;

        if ($readyForReview && in_array($ticket->status, [TicketStatus::Todo, TicketStatus::InProgress], true)) {
            return TicketStatus::InReview;
        }

        return null;
    }
}
