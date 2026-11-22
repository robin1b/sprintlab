<?php

namespace Tests\Feature\Scenario;

use App\Models\Scenario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportScenariosCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_imports_a_given_file(): void
    {
        $this->artisan('sprintlab:import-scenarios', ['path' => resource_path('scenarios/demo-sprint.json')])
            ->expectsOutputToContain('Imported demo-sprint: Demo sprint (3 tickets, 6 events)')
            ->assertSuccessful();

        $this->assertSame(1, Scenario::count());
    }

    public function test_it_imports_the_configured_paths_and_skips_missing_ones(): void
    {
        config(['sprintlab.scenario_paths' => [
            resource_path('scenarios'),
            sys_get_temp_dir().'/sprintlab-does-not-exist',
        ]]);

        $this->artisan('sprintlab:import-scenarios')
            ->expectsOutputToContain('Imported demo-sprint')
            ->assertSuccessful();

        $this->assertSame(1, Scenario::count());
    }

    public function test_it_fails_with_a_message_for_a_missing_path(): void
    {
        $this->artisan('sprintlab:import-scenarios', ['path' => '/no/such/place.json'])
            ->expectsOutputToContain('does not exist')
            ->assertFailed();
    }

    public function test_it_fails_with_a_message_for_an_invalid_file(): void
    {
        $path = sys_get_temp_dir().'/sprintlab-invalid-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($path, '{"slug": "x"}');

        try {
            $this->artisan('sprintlab:import-scenarios', ['path' => $path])
                ->expectsOutputToContain(basename($path))
                ->assertFailed();
        } finally {
            unlink($path);
        }

        $this->assertSame(0, Scenario::count());
    }
}
