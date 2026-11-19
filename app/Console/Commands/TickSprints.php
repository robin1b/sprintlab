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
        $this->info('Fired '.$engine->runDue().' scenario events.');

        return self::SUCCESS;
    }
}
