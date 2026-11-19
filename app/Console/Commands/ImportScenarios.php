<?php

namespace App\Console\Commands;

use App\Services\Scenario\ScenarioImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('sprintlab:import-scenarios {path? : A scenario file or a folder with scenario files}')]
#[Description('Import scenario JSON files into the database')]
class ImportScenarios extends Command
{
    public function handle(ScenarioImporter $importer): int
    {
        try {
            $files = $this->files($importer);

            foreach ($files as $file) {
                $scenario = $importer->importFile($file);

                $this->line(sprintf(
                    'Imported %s: %s (%d tickets, %d events)',
                    $scenario->slug,
                    $scenario->title,
                    $scenario->scenarioTickets()->count(),
                    $scenario->scenarioEvents()->count(),
                ));
            }
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function files(ScenarioImporter $importer): array
    {
        $path = $this->argument('path');

        if (is_string($path) && $path !== '') {
            if (! file_exists($path)) {
                throw new InvalidArgumentException("Path {$path} does not exist.");
            }

            return is_dir($path) ? $importer->filesIn($path) : [$path];
        }

        $files = [];

        // A configured folder that is not there (no private scenarios on this machine) is fine.
        foreach ((array) config('sprintlab.scenario_paths') as $configured) {
            if (is_string($configured) && is_dir($configured)) {
                array_push($files, ...$importer->filesIn($configured));
            }
        }

        return $files;
    }
}
