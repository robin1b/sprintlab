<?php

namespace App\Services\Sprint;

use App\Enums\ScenarioTrigger;
use App\Enums\SprintStatus;
use App\Enums\TicketStatus;
use App\Jobs\ReplyToChatMessage;
use App\Models\Message;
use App\Models\Repository;
use App\Models\Scenario;
use App\Models\Sprint;
use App\Models\User;
use App\Services\Scenario\ScenarioEngine;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Starting, pausing and resuming a sprint, and starting its days.
 */
class SprintService
{
    // A sprint is played in real time: the user has a week to finish it.
    private const DEADLINE_DAYS = 7;

    public function __construct(private readonly ScenarioEngine $engine) {}

    public function start(User $user, Scenario $scenario): Sprint
    {
        return DB::transaction(function () use ($user, $scenario): Sprint {
            if ($this->currentFor($user) !== null) {
                throw new DomainException('You already have a sprint that is not finished.');
            }

            $sprint = Sprint::create([
                'user_id' => $user->id,
                'scenario_id' => $scenario->id,
                'repository_id' => $this->connectedRepositoryOf($user)?->id,
                'status' => SprintStatus::Active,
                'current_day' => 1,
                'started_at' => Carbon::now(),
                'deadline_at' => Carbon::now()->addDays(self::DEADLINE_DAYS),
            ]);

            foreach ($scenario->scenarioTickets()->orderBy('sort')->orderBy('id')->get() as $source) {
                $sprint->tickets()->create([
                    'scenario_ticket_id' => $source->id,
                    'key' => $source->key,
                    'day' => $source->day,
                    'type' => $source->type,
                    'reporter' => $source->reporter,
                    'title' => $source->title,
                    'description' => $source->description,
                    'acceptance_criteria' => $source->acceptance_criteria,
                    'status' => TicketStatus::Todo,
                    'released_at' => null,
                    'sort' => $source->sort,
                ]);
            }

            $this->engine->dispatch($sprint, ScenarioTrigger::SprintStarted);
            $this->startDay($sprint, 1);

            return $sprint;
        });
    }

    /**
     * Release the tickets of a day (those that are not held back for an event)
     * and let the scenario react.
     */
    public function startDay(Sprint $sprint, int $day): void
    {
        $sprint->update(['current_day' => $day]);

        $sprint->tickets()
            ->where('day', $day)
            ->whereNull('released_at')
            ->whereHas('scenarioTicket', fn ($tickets) => $tickets->where('released_at_start', true))
            ->update(['released_at' => Carbon::now()]);

        $this->engine->dispatch($sprint, ScenarioTrigger::DayStarted, day: $day);
    }

    public function pause(Sprint $sprint): void
    {
        if (! $sprint->isActive()) {
            throw new DomainException('Only an active sprint can be paused.');
        }

        $sprint->update(['status' => SprintStatus::Paused, 'paused_at' => Carbon::now()]);
    }

    /**
     * Continue after a pause. Time spent paused does not count, so the
     * deadline and the scheduled events move by the length of the pause.
     */
    public function resume(Sprint $sprint): void
    {
        if (! $sprint->isPaused() || $sprint->paused_at === null) {
            throw new DomainException('Only a paused sprint can be resumed.');
        }

        $seconds = max(0, Carbon::now()->getTimestamp() - $sprint->paused_at->getTimestamp());

        DB::transaction(function () use ($sprint, $seconds): void {
            foreach ($sprint->sprintEvents()->whereNull('fired_at')->get() as $event) {
                $event->update(['due_at' => $event->due_at->addSeconds($seconds)]);
            }

            $sprint->update([
                'status' => SprintStatus::Active,
                'deadline_at' => $sprint->deadline_at->addSeconds($seconds),
                'paused_at' => null,
            ]);
        });

        // A chat message that arrived just before the pause was left
        // unanswered. Ask the colleagues to answer now, once per conversation.
        $sprint->messages()
            ->where('from_user', true)
            ->whereNull('answered_at')
            ->get()
            ->unique(fn (Message $message): string => $message->colleague->value)
            ->each(fn (Message $message) => ReplyToChatMessage::dispatch($message->id));
    }

    /**
     * The repository where colleagues open their pull requests: the one linked
     * to the sprint or, when GitHub was connected after the sprint started,
     * the user's newest one (which is then linked).
     */
    public function repositoryFor(Sprint $sprint): ?Repository
    {
        if ($sprint->repository_id !== null) {
            return $sprint->repository;
        }

        $repository = $this->connectedRepositoryOf($sprint->user);

        if ($repository !== null) {
            $sprint->update(['repository_id' => $repository->id]);
            $sprint->setRelation('repository', $repository);
        }

        return $repository;
    }

    /**
     * The repository a user's sprints work in: the one they chose, when it is
     * still connected, otherwise their newest one.
     */
    public function connectedRepositoryOf(User $user): ?Repository
    {
        $usable = fn (Builder $installations) => $installations
            ->where('user_id', $user->id)
            ->whereNull('suspended_at');

        if ($user->practice_repository_id !== null) {
            $chosen = Repository::query()
                ->whereKey($user->practice_repository_id)
                ->whereHas('installation', $usable)
                ->first();

            if ($chosen !== null) {
                return $chosen;
            }
        }

        return Repository::query()
            ->whereHas('installation', $usable)
            ->latest('id')
            ->first();
    }

    public function currentFor(User $user): ?Sprint
    {
        return $user->sprints()
            ->where('status', '!=', SprintStatus::Completed)
            ->latest('id')
            ->first();
    }
}
