<?php

namespace Tests\Feature\Deploy;

use App\Console\Commands\DeploySite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DeploySiteTest extends TestCase
{
    use RefreshDatabase;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        // The command keeps its state in storage/: use a folder of our own,
        // so a test never touches the real state of this machine.
        $this->storage = sys_get_temp_dir().'/sprintlab-deploy-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->storage.'/app/private/scenarios');
        File::ensureDirectoryExists($this->storage.'/framework/cache');
        File::ensureDirectoryExists($this->storage.'/logs');

        $this->app->useStoragePath($this->storage);

        config(['sprintlab.scenario_paths' => [resource_path('scenarios'), $this->storage.'/app/private/scenarios']]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);

        parent::tearDown();
    }

    public function test_the_first_run_migrates_imports_scenarios_and_clears_caches(): void
    {
        Process::fake();

        $this->artisan('site:deploy')->assertSuccessful();

        foreach (['migrate', 'sprintlab:import-scenarios', 'optimize:clear'] as $command) {
            Process::assertRan(fn (PendingProcess $process): bool => in_array($command, (array) $process->command, true));
        }

        $state = DeploySite::state();

        $this->assertTrue($state['ok']);
        $this->assertSame('New version ready: migrate, sprintlab:import-scenarios, optimize:clear.', $state['message']);
    }

    public function test_nothing_happens_when_nothing_changed(): void
    {
        $runs = $this->countProcesses();

        $this->artisan('site:deploy')->assertSuccessful();
        $afterFirst = $runs();

        $this->artisan('site:deploy')->assertSuccessful();

        $this->assertSame(3, $afterFirst);
        $this->assertSame($afterFirst, $runs());
    }

    public function test_a_scenario_uploaded_by_hand_starts_a_new_run(): void
    {
        $runs = $this->countProcesses();

        $this->artisan('site:deploy')->assertSuccessful();

        File::put($this->storage.'/app/private/scenarios/junior-sprint-1.json', '{"slug": "junior-sprint-1"}');

        $this->artisan('site:deploy')->assertSuccessful();

        $this->assertSame(6, $runs());
    }

    public function test_force_runs_everything_again(): void
    {
        $runs = $this->countProcesses();

        $this->artisan('site:deploy')->assertSuccessful();
        $this->artisan('site:deploy --force')->assertSuccessful();

        $this->assertSame(6, $runs());
    }

    public function test_a_failure_is_recorded_and_retried_later_not_every_minute(): void
    {
        $runs = 0;
        $fail = true;

        Process::fake(function () use (&$runs, &$fail) {
            $runs++;

            return $fail ? Process::result(errorOutput: 'SQLSTATE[HY000] [1045] Access denied', exitCode: 1) : Process::result();
        });

        $this->artisan('site:deploy')->assertFailed();

        $this->assertFalse(DeploySite::state()['ok']);
        $this->assertStringContainsString('Failed at step 1: migrate --force: SQLSTATE[HY000] [1045] Access denied', DeploySite::state()['message']);

        // A minute later nothing runs, also when the cause is gone.
        $fail = false;
        $afterFailure = $runs;
        $this->artisan('site:deploy')->assertSuccessful();
        $this->assertSame($afterFailure, $runs);

        $this->travel(DeploySite::RETRY_AFTER_MINUTES + 1)->minutes();
        $this->artisan('site:deploy')->assertSuccessful();

        $this->assertTrue(DeploySite::state()['ok']);
    }

    public function test_the_scheduler_runs_the_deploy_the_queue_the_sprint_events_and_the_cleanup(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('site:deploy')
            ->expectsOutputToContain('queue:work')
            ->expectsOutputToContain('sprintlab:tick')
            ->expectsOutputToContain('model:prune')
            ->assertSuccessful();
    }

    public function test_the_admin_page_shows_the_last_deployment(): void
    {
        Process::fake();
        $this->artisan('site:deploy');

        config(['sprintlab.admin_github_ids' => [4242]]);

        $this->actingAs(User::factory()->create(['github_id' => 4242]))
            ->get(route('admin.usage'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Usage')
                ->has('deployment', 2)
                ->where('deployment.0.key', 'packages')
                ->where('deployment.1.key', 'release')
                ->where('deployment.1.ok', true)
                ->where('deployment.1.message', 'New version ready: migrate, sprintlab:import-scenarios, optimize:clear.')
                ->whereType('deployment.1.at', 'string'));
    }

    public function test_the_admin_page_works_before_the_first_deployment(): void
    {
        config(['sprintlab.admin_github_ids' => [4242]]);

        $this->actingAs(User::factory()->create(['github_id' => 4242]))
            ->get(route('admin.usage'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('deployment.1.ok', null)
                ->where('deployment.1.at', null)
                ->where('deployment.1.message', null));
    }

    /**
     * Fake every process and return a counter of how many ran.
     *
     * @return \Closure(): int
     */
    private function countProcesses(): \Closure
    {
        $runs = 0;

        Process::fake(function () use (&$runs) {
            $runs++;

            return Process::result();
        });

        return function () use (&$runs): int {
            return $runs;
        };
    }
}
