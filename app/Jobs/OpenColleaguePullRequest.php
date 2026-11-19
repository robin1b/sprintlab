<?php

namespace App\Jobs;

use App\Models\ScenarioEvent;
use App\Models\Ticket;
use App\Services\GitHub\ColleaguePullRequestService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class OpenColleaguePullRequest implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    // Several GitHub calls in a row: one per file, plus the branch and the pull request.
    public int $timeout = 120;

    // `sprintlab:tick` queues this job again every minute while GitHub is not
    // connected. The lock keeps one job per ticket waiting at a time.
    public int $uniqueFor = 300;

    public function __construct(public int $ticketId, public int $scenarioEventId) {}

    public function uniqueId(): string
    {
        return (string) $this->ticketId;
    }

    public function handle(ColleaguePullRequestService $pullRequests): void
    {
        $ticket = Ticket::find($this->ticketId);
        $event = ScenarioEvent::find($this->scenarioEventId);

        if ($ticket === null || $event === null) {
            return;
        }

        $pullRequests->open($ticket, $event);
    }
}
