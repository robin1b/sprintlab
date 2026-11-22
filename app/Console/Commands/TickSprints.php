<?php

namespace App\Console\Commands;

use App\Services\Scenario\ScenarioEngine;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('sprintlab:tick')]
#[Description('Fire the scenario events that are due')]
class TickSprints extends Command
{
    public function handle(ScenarioEngine $engine): int
    {
        // First the retries, so a pull request job queued by an event that fires
        // in this same tick is not queued twice.
        $retried = $engine->retryPullRequests();

        $this->info('Fired '.$engine->runDue().' scenario events.');

        if ($retried > 0) {
            $this->info("Queued {$retried} pull request retries.");
        }

        return self::SUCCESS;
    }
}
