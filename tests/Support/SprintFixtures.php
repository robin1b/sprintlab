<?php

namespace Tests\Support;

use App\Models\Scenario;
use App\Models\Sprint;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Scenario\ScenarioImporter;
use App\Services\Sprint\SprintService;

/**
 * Helpers for tests that play the demo scenario.
 */
trait SprintFixtures
{
    protected function demoScenario(): Scenario
    {
        return app(ScenarioImporter::class)->importFile(resource_path('scenarios/demo-sprint.json'));
    }

    protected function startDemoSprint(?User $user = null): Sprint
    {
        $user ??= User::factory()->create(['name' => 'Robin Broos']);

        return app(SprintService::class)->start($user, $this->demoScenario());
    }

    protected function ticket(Sprint $sprint, string $key): Ticket
    {
        return $sprint->tickets()->where('key', $key)->firstOrFail();
    }
}
