<?php

namespace App\Services\Scenario;

use App\Enums\ScenarioAction;
use App\Enums\ScenarioTrigger;
use App\Enums\SprintStatus;
use App\Enums\TicketStatus;
use App\Jobs\OpenColleaguePullRequest;
use App\Models\Message;
use App\Models\ScenarioEvent;
use App\Models\Sprint;
use App\Models\SprintEvent;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Runs the scripted events of a scenario for a sprint.
 *
 * `dispatch()` schedules the events that match something that just happened,
 * `runDue()` carries out the scheduled events whose time has come.
 */
class ScenarioEngine
{
    /**
     * Schedule the events for a trigger, then run what is due.
     */
    public function dispatch(Sprint $sprint, ScenarioTrigger $trigger, ?Ticket $ticket = null, ?int $day = null): void
    {
        $events = $sprint->scenario->scenarioEvents()
            ->where('trigger', $trigger)
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        foreach ($events as $event) {
            if (! $this->matches($event, $trigger, $ticket, $day)) {
                continue;
            }

            // The unique key (sprint, event) makes this safe to repeat: an event is scheduled once.
            SprintEvent::firstOrCreate(
                ['sprint_id' => $sprint->id, 'scenario_event_id' => $event->id],
                ['due_at' => Carbon::now()->addMinutes($event->delay_minutes)],
            );
        }

        $this->runDue($sprint);
    }

    /**
     * Fire every scheduled event that is due.
     *
     * Paused sprints wait. Completed sprints are skipped too, except for
     * their `sprint_completed` events: the sprint is already completed when
     * those are scheduled, and they are the goodbye of the scenario.
     *
     * @return int the number of events fired
     */
    public function runDue(?Sprint $sprint = null): int
    {
        $due = SprintEvent::query()
            ->with(['sprint.user', 'scenarioEvent'])
            ->whereNull('fired_at')
            ->where('due_at', '<=', Carbon::now())
            ->where(function (Builder $query): void {
                $query
                    ->whereHas('sprint', fn (Builder $sprints) => $sprints->where('status', SprintStatus::Active))
                    ->orWhere(function (Builder $query): void {
                        $query
                            ->whereHas('sprint', fn (Builder $sprints) => $sprints->where('status', SprintStatus::Completed))
                            ->whereHas('scenarioEvent', fn (Builder $events) => $events->where('trigger', ScenarioTrigger::SprintCompleted));
                    });
            })
            ->when($sprint !== null, fn (Builder $query) => $query->where('sprint_id', $sprint?->id))
            ->get()
            ->sortBy([
                ['due_at', 'asc'],
                fn (SprintEvent $a, SprintEvent $b): int => $a->scenarioEvent->sort <=> $b->scenarioEvent->sort,
                ['id', 'asc'],
            ]);

        $fired = 0;

        foreach ($due as $sprintEvent) {
            $fired += DB::transaction(function () use ($sprintEvent): int {
                // Claim the row first. If two runs overlap, only one gets it.
                $claimed = SprintEvent::whereKey($sprintEvent->id)
                    ->whereNull('fired_at')
                    ->update(['fired_at' => Carbon::now()]);

                if ($claimed === 0) {
                    return 0;
                }

                $this->perform($sprintEvent->sprint, $sprintEvent->scenarioEvent);

                return 1;
            });
        }

        return $fired;
    }

    /**
     * Queue the pull request job again for every fired `open_pull_request`
     * event whose ticket still has no pull request: GitHub was not connected
     * yet, or the first try failed. The job is safe to run twice.
     *
     * @return int the number of jobs queued
     */
    public function retryPullRequests(): int
    {
        $rows = DB::table('sprint_events')
            ->join('scenario_events', 'scenario_events.id', '=', 'sprint_events.scenario_event_id')
            ->join('sprints', 'sprints.id', '=', 'sprint_events.sprint_id')
            ->join('tickets', function (JoinClause $join): void {
                $join->on('tickets.sprint_id', '=', 'sprint_events.sprint_id')
                    ->on('tickets.key', '=', 'scenario_events.ticket_key');
            })
            ->whereNotNull('sprint_events.fired_at')
            ->where('scenario_events.action', ScenarioAction::OpenPullRequest->value)
            ->where('sprints.status', SprintStatus::Active->value)
            ->whereNull('tickets.pull_request_id')
            ->where('tickets.status', '!=', TicketStatus::Done->value)
            ->get(['tickets.id as ticket_id', 'scenario_events.id as event_id']);

        foreach ($rows as $row) {
            OpenColleaguePullRequest::dispatch((int) $row->ticket_id, (int) $row->event_id);
        }

        return $rows->count();
    }

    /**
     * Ticket-specific events only match their ticket. That only makes sense
     * for ticket triggers: on other triggers `ticket_key` just says which
     * ticket the event is about (the one to release, update or talk about).
     */
    private function matches(ScenarioEvent $event, ScenarioTrigger $trigger, ?Ticket $ticket, ?int $day): bool
    {
        if ($event->day !== null && $event->day !== $day) {
            return false;
        }

        if ($event->ticket_key !== null && $trigger->isTicketTrigger() && $ticket?->key !== $event->ticket_key) {
            return false;
        }

        return true;
    }

    private function perform(Sprint $sprint, ScenarioEvent $event): void
    {
        $ticket = $event->ticket_key === null
            ? null
            : $sprint->tickets()->where('key', $event->ticket_key)->first();

        switch ($event->action) {
            case ScenarioAction::Message:
                break;

            case ScenarioAction::ReleaseTicket:
                if ($ticket !== null && ! $ticket->isReleased()) {
                    $ticket->update(['released_at' => Carbon::now()]);
                }
                break;

            case ScenarioAction::UpdateTicket:
                if ($ticket !== null) {
                    $this->updateTicket($ticket, $event);
                }
                break;

            case ScenarioAction::OpenPullRequest:
                if ($ticket !== null) {
                    if (! $ticket->isReleased()) {
                        $ticket->update(['released_at' => Carbon::now()]);
                    }

                    OpenColleaguePullRequest::dispatch($ticket->id, $event->id);
                }
                break;
        }

        $this->sendMessage($sprint, $event, $ticket);
    }

    private function updateTicket(Ticket $ticket, ScenarioEvent $event): void
    {
        $payload = $event->payload;

        $criteria = array_values(array_filter((array) ($payload['add_criteria'] ?? []), 'is_string'));
        $append = $payload['description_append'] ?? null;
        $title = $payload['title'] ?? null;

        if ($criteria !== []) {
            $ticket->acceptance_criteria = [...$ticket->acceptance_criteria, ...$criteria];
        }

        if (is_string($append) && trim($append) !== '') {
            $ticket->description = rtrim($ticket->description)."\n\n".trim($append);
        }

        if (is_string($title) && trim($title) !== '') {
            $ticket->title = $title;
        }

        $ticket->save();
    }

    private function sendMessage(Sprint $sprint, ScenarioEvent $event, ?Ticket $ticket): void
    {
        $body = $event->payload['body'] ?? null;

        if (! is_string($body) || trim($body) === '' || $event->colleague === null) {
            return;
        }

        $name = trim($sprint->user->name);
        $firstName = explode(' ', $name)[0];

        Message::create([
            'sprint_id' => $sprint->id,
            'colleague' => $event->colleague,
            'from_user' => false,
            'body' => str_replace(['{first_name}', '{name}'], [$firstName, $name], $body),
            'ticket_id' => $ticket?->id,
        ]);
    }
}
