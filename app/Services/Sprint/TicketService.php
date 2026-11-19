<?php

namespace App\Services\Sprint;

use App\Enums\ScenarioTrigger;
use App\Enums\SprintStatus;
use App\Enums\TicketStatus;
use App\Jobs\PrepareSprintEvaluation;
use App\Models\Sprint;
use App\Models\Ticket;
use App\Services\Scenario\ScenarioEngine;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Moving tickets over the board, and what follows from it.
 */
class TicketService
{
    public function __construct(
        private readonly ScenarioEngine $engine,
        private readonly SprintService $sprints,
    ) {}

    public function move(Ticket $ticket, TicketStatus $to): Ticket
    {
        // Read the sprint again: the one on the ticket may be out of date.
        $sprint = $ticket->sprint()->firstOrFail();

        if (! $sprint->isActive()) {
            throw new DomainException('This sprint is not active.');
        }

        if (! $ticket->isReleased()) {
            throw new DomainException('This ticket has not been released yet.');
        }

        if ($ticket->status === $to) {
            return $ticket;
        }

        DB::transaction(function () use ($ticket, $sprint, $to): void {
            $ticket->update(['status' => $to]);

            $trigger = match ($to) {
                TicketStatus::Todo => null,
                TicketStatus::InProgress => ScenarioTrigger::TicketStarted,
                TicketStatus::InReview => ScenarioTrigger::TicketInReview,
                TicketStatus::Done => ScenarioTrigger::TicketDone,
            };

            if ($trigger !== null) {
                $this->engine->dispatch($sprint, $trigger, $ticket);
            }

            if ($to === TicketStatus::Done) {
                $this->advance($sprint);
            }
        });

        return $ticket;
    }

    /**
     * When everything released so far is done, the next day starts. After the
     * last day the sprint is completed.
     */
    private function advance(Sprint $sprint): void
    {
        // The scenario may just have released or changed tickets.
        $sprint->refresh();

        $unfinished = $sprint->tickets()
            ->whereNotNull('released_at')
            ->where('day', '<=', $sprint->current_day)
            ->where('status', '!=', TicketStatus::Done)
            ->exists();

        if ($unfinished) {
            return;
        }

        if ($sprint->current_day < $sprint->scenario->duration_days) {
            $this->sprints->startDay($sprint, $sprint->current_day + 1);

            return;
        }

        // Complete first, then dispatch: `runDue` skips completed sprints
        // except for their `sprint_completed` events, which fire right away.
        $sprint->update(['status' => SprintStatus::Completed, 'completed_at' => Carbon::now()]);

        $this->engine->dispatch($sprint, ScenarioTrigger::SprintCompleted);

        // After the commit, so the job always finds the completed sprint.
        PrepareSprintEvaluation::dispatch($sprint->id)->afterCommit();
    }
}
