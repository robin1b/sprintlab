<?php

namespace App\Console\Commands;

use App\Enums\SprintStatus;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Message;
use App\Models\Scenario;
use App\Models\Sprint;
use App\Models\SprintEvent;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Scenario\ScenarioEngine;
use App\Services\Sprint\SprintService;
use App\Services\Sprint\TicketService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Plays a scenario from start to finish without GitHub and without AI, to
 * check its script: does every ticket come out, does every event fire, does
 * the sprint end?
 *
 * A stand-in user finishes each ticket in the order of the board, with the
 * clock moved forward between the steps. Nothing is kept: the whole run is
 * rolled back, and jobs (a colleague's pull request, the evaluation) are
 * caught instead of run.
 */
#[Signature('sprintlab:rehearse {slug : The slug of an imported scenario} {--minutes=45 : Minutes of work between two status changes}')]
#[Description('Play a scenario without GitHub or AI and show what happens when')]
class RehearseScenario extends Command
{
    // A script that needs more steps than this is going in circles.
    private const MAX_STEPS = 200;

    private Carbon $startedAt;

    /** @var list<int> */
    private array $shownEvents = [];

    /** @var list<int> */
    private array $shownMessages = [];

    public function handle(SprintService $sprints, TicketService $tickets, ScenarioEngine $engine): int
    {
        $scenario = Scenario::where('slug', (string) $this->argument('slug'))->first();

        if ($scenario === null) {
            $this->error('No imported scenario with that slug. Import it first with sprintlab:import-scenarios.');

            return self::FAILURE;
        }

        $minutes = max(1, (int) $this->option('minutes'));

        Bus::fake();
        DB::beginTransaction();

        try {
            $this->startedAt = Carbon::now()->startOfMinute();
            Carbon::setTestNow($this->startedAt);

            $user = User::create([
                'name' => 'Rehearsal User',
                'email' => 'rehearsal-'.Str::random(12).'@example.test',
                'github_id' => random_int(900_000_000, 999_999_999),
                'github_username' => 'rehearsal-'.Str::lower(Str::random(8)),
            ]);

            $sprint = $sprints->start($user, $scenario);
            $this->report($sprint, 'The sprint starts.');

            $steps = 0;

            while ($sprint->refresh()->status !== SprintStatus::Completed && $steps++ < self::MAX_STEPS) {
                $ticket = $this->nextTicket($sprint);

                if ($ticket === null) {
                    // Nothing on the board: wait for an event that is still to come.
                    $next = SprintEvent::where('sprint_id', $sprint->id)->whereNull('fired_at')->min('due_at');

                    if ($next === null) {
                        break;
                    }

                    Carbon::setTestNow(Carbon::parse($next));
                    $engine->runDue($sprint);
                    $this->report($sprint, 'Nothing to do: waited for the next event.');

                    continue;
                }

                $to = match (true) {
                    // The user reviews a colleague's pull request: a good review finishes the ticket.
                    $ticket->type === TicketType::Review => TicketStatus::Done,
                    $ticket->status === TicketStatus::Todo => TicketStatus::InProgress,
                    $ticket->status === TicketStatus::InProgress => TicketStatus::InReview,
                    default => TicketStatus::Done,
                };

                $tickets->move($ticket, $to);
                $this->report($sprint, "{$ticket->key} moves to ".str_replace('_', ' ', $to->value).'.');

                $this->workUntil($sprint, $engine, Carbon::now()->addMinutes($minutes));
            }

            // What was scheduled with a delay still fires, even after the last ticket.
            $last = SprintEvent::where('sprint_id', $sprint->id)->whereNull('fired_at')->max('due_at');

            if ($last !== null) {
                Carbon::setTestNow(Carbon::parse($last));
                $engine->runDue($sprint);
                $this->report($sprint, 'Waited for the last events.');
            }

            return $this->verdict($sprint->refresh());
        } finally {
            DB::rollBack();
            Carbon::setTestNow();
        }
    }

    /**
     * Move the clock forward, stopping at every event that comes due on the
     * way, so the report shows each event at its own time.
     */
    private function workUntil(Sprint $sprint, ScenarioEngine $engine, Carbon $until): void
    {
        while (true) {
            $next = SprintEvent::where('sprint_id', $sprint->id)
                ->whereNull('fired_at')
                ->where('due_at', '<=', $until)
                ->min('due_at');

            if ($next === null) {
                break;
            }

            Carbon::setTestNow(Carbon::parse($next)->max(Carbon::now()));

            if ($engine->runDue($sprint) === 0) {
                // Due but not fired (for example a paused sprint): do not wait on it.
                break;
            }

            $this->report($sprint, null);
        }

        Carbon::setTestNow($until);
    }

    /**
     * The first released ticket of the current day (or an earlier one) that is not done.
     */
    private function nextTicket(Sprint $sprint): ?Ticket
    {
        return $sprint->tickets()
            ->whereNotNull('released_at')
            ->where('status', '!=', TicketStatus::Done)
            ->orderBy('day')
            ->orderBy('sort')
            ->orderBy('id')
            ->first();
    }

    /**
     * Print what happened since the last report: the step, the events that
     * fired and the messages that arrived.
     */
    private function report(Sprint $sprint, ?string $step): void
    {
        $clock = $this->clock();

        if ($step !== null) {
            $this->line("<info>{$clock}</info>  day {$sprint->refresh()->current_day}  {$step}");
        }

        $fired = SprintEvent::with('scenarioEvent')
            ->where('sprint_id', $sprint->id)
            ->whereNotNull('fired_at')
            ->whereNotIn('id', $this->shownEvents)
            ->orderBy('fired_at')
            ->orderBy('id')
            ->get();

        foreach ($fired as $sprintEvent) {
            $event = $sprintEvent->scenarioEvent;
            $this->shownEvents[] = $sprintEvent->id;

            $about = $event->ticket_key === null ? '' : " ({$event->ticket_key})";
            $this->line("{$clock}         event <comment>{$event->key}</comment>: {$event->action->value}{$about}");
        }

        $messages = Message::where('sprint_id', $sprint->id)
            ->whereNotIn('id', $this->shownMessages)
            ->orderBy('id')
            ->get();

        foreach ($messages as $message) {
            $this->shownMessages[] = $message->id;
            $this->line("{$clock}         {$message->colleague->displayName()}: ".Str::limit($message->body, 90));
        }
    }

    private function verdict(Sprint $sprint): int
    {
        $problems = [];

        if ($sprint->status !== SprintStatus::Completed) {
            $problems[] = 'The sprint did not end: it got stuck on day '.$sprint->current_day.'.';
        }

        foreach ($sprint->tickets()->whereNull('released_at')->pluck('key') as $key) {
            $problems[] = "Ticket {$key} never came on the board.";
        }

        $fired = SprintEvent::where('sprint_id', $sprint->id)->whereNotNull('fired_at')->pluck('scenario_event_id');

        foreach ($sprint->scenario->scenarioEvents()->whereNotIn('id', $fired)->pluck('key') as $key) {
            $problems[] = "Event {$key} never fired.";
        }

        $this->newLine();

        if ($problems === []) {
            $this->info('The script holds: every ticket came out, every event fired and the sprint ended after '.$this->clock().'.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        return self::FAILURE;
    }

    /**
     * Time since the start of the sprint, like +3h15.
     */
    private function clock(): string
    {
        $minutes = (int) $this->startedAt->diffInMinutes(Carbon::now());

        return sprintf('+%dh%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
