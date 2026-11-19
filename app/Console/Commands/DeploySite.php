<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Finishes a deployment without SSH.
 *
 * The host only copies the files of a new version. The scheduler runs this
 * command every minute: when the migrations, the scenarios, the build or the
 * packages changed since the last run, it migrates the database, imports the
 * scenarios and clears the caches. The result is shown on the admin page.
 *
 * The Composer packages are installed one step earlier, by scripts/cron.php,
 * because this command cannot start without them.
 */
#[Signature('site:deploy {--force : Run all steps, even when nothing changed}')]
#[Description('Migrate, import scenarios and clear caches after a new version was deployed')]
class DeploySite extends Command
{
    /**
     * After a failed run, wait this long before trying again.
     */
    public const RETRY_AFTER_MINUTES = 10;

    public function handle(): int
    {
        $state = self::state();
        $fingerprint = $this->fingerprint();

        if (! $this->option('force') && ($state['fingerprint'] ?? null) === $fingerprint) {
            if ($state['ok'] ?? false) {
                return self::SUCCESS;
            }

            if (isset($state['at']) && Carbon::parse($state['at'])->diffInMinutes(now()) < self::RETRY_AFTER_MINUTES) {
                return self::SUCCESS;
            }
        }

        $steps = [];

        try {
            $steps[] = $this->artisan(['migrate', '--force']);
            $steps[] = $this->artisan(['sprintlab:import-scenarios']);
            // Config and routes are not cached on purpose: a change in .env works at once.
            $steps[] = $this->artisan(['optimize:clear']);

            $ok = true;
            $message = 'New version ready: '.implode(', ', $steps).'.';
        } catch (Throwable $exception) {
            $ok = false;
            $message = 'Failed at step '.(count($steps) + 1).': '.$exception->getMessage();

            Log::error('site:deploy failed', ['error' => $exception->getMessage()]);
        }

        self::saveState([
            'fingerprint' => $fingerprint,
            'ok' => $ok,
            'at' => now()->toIso8601String(),
            'message' => $message,
        ]);

        $line = '['.now()->format('Y-m-d H:i:s').'] '.$message;

        $ok ? $this->info($line) : $this->error($line);

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The last result, for the admin page.
     *
     * @return array{fingerprint?: string, ok?: bool, at?: string, message?: string}
     */
    public static function state(): array
    {
        $path = self::statePath();

        if (! is_file($path)) {
            return [];
        }

        $state = json_decode((string) file_get_contents($path), true);

        return is_array($state) ? $state : [];
    }

    public static function statePath(): string
    {
        return storage_path('app/private/deploy-state.json');
    }

    /**
     * @param  array{fingerprint: string, ok: bool, at: string, message: string}  $state
     */
    private static function saveState(array $state): void
    {
        File::ensureDirectoryExists(dirname(self::statePath()));
        File::put(self::statePath(), (string) json_encode($state, JSON_PRETTY_PRINT));
    }

    /**
     * Changes when new packages, migrations, scenarios or a new build arrived.
     */
    private function fingerprint(): string
    {
        $migrations = collect(File::files(database_path('migrations')))
            ->map(fn (\SplFileInfo $file): string => $file->getFilename())
            ->sort()
            ->implode(',');

        return sha1(implode('|', [
            $this->hashOf(base_path('composer.lock')),
            $migrations,
            $this->hashOf(public_path('build/manifest.json')),
            $this->scenarioFingerprint(),
        ]));
    }

    /**
     * A scenario file that was added or changed (also one uploaded by hand to
     * the private folder) is imported on the next run.
     */
    private function scenarioFingerprint(): string
    {
        $files = [];

        foreach ((array) config('sprintlab.scenario_paths') as $directory) {
            if (! is_string($directory) || ! is_dir($directory)) {
                continue;
            }

            foreach (File::allFiles($directory) as $file) {
                $files[] = $file->getPathname().':'.$this->hashOf($file->getPathname());
            }
        }

        sort($files);

        return sha1(implode(',', $files));
    }

    private function hashOf(string $path): string
    {
        return is_file($path) ? (string) sha1_file($path) : '';
    }

    /**
     * Runs an artisan command in a fresh PHP process (so freshly installed
     * packages are used), or in this process when the host does not allow
     * starting processes.
     *
     * @param  list<string>  $arguments
     */
    private function artisan(array $arguments): string
    {
        if (function_exists('proc_open')) {
            $result = Process::path(base_path())->timeout(300)->run([PHP_BINARY, 'artisan', ...$arguments, '--no-interaction']);

            if ($result->failed()) {
                throw new RuntimeException(implode(' ', $arguments).': '.trim(substr($result->errorOutput() ?: $result->output(), -500)));
            }

            return $arguments[0];
        }

        $parameters = collect(array_slice($arguments, 1))
            ->mapWithKeys(function (string $argument): array {
                [$key, $value] = array_pad(explode('=', $argument, 2), 2, true);

                return [$key => $value];
            })
            ->all();

        if (Artisan::call($arguments[0], $parameters) !== self::SUCCESS) {
            throw new RuntimeException(implode(' ', $arguments).': '.trim(Artisan::output()));
        }

        return $arguments[0];
    }
}
