<?php

namespace App\Support\Deploy;

use Throwable;

/**
 * Installs the Composer packages on a host without SSH.
 *
 * The cron job runs scripts/cron.php every minute. That script loads this
 * class before Laravel starts: when vendor/ is missing or does not match
 * composer.lock, Laravel cannot start at all. So this class may only use
 * plain PHP, nothing from vendor/.
 */
class PackageInstaller
{
    /**
     * After a failed run, wait this long before trying again.
     */
    public const RETRY_AFTER_SECONDS = 600;

    /**
     * Composer gets this long before it is stopped.
     */
    public const TIMEOUT_SECONDS = 900;

    public const CURRENT = 'current';

    public const INSTALLED = 'installed';

    public const FAILED = 'failed';

    public const WAITING = 'waiting';

    public const BUSY = 'busy';

    public function __construct(
        private readonly string $root,
        private readonly ?string $composer = null,
        private readonly string $php = PHP_BINARY,
    ) {}

    /**
     * Install the packages when composer.lock asks for something else than
     * vendor/ holds.
     *
     * @return array{status: string, message: string|null}
     */
    public function run(): array
    {
        if ($this->upToDate()) {
            return ['status' => self::CURRENT, 'message' => null];
        }

        $state = self::state($this->root);
        $lock = $this->lockHash();

        // Composer failed for this same composer.lock a moment ago: do not hammer it every minute.
        if (($state['ok'] ?? null) === false
            && ($state['attempted'] ?? null) === $lock
            && isset($state['at'])
            && time() - (int) strtotime((string) $state['at']) < self::RETRY_AFTER_SECONDS) {
            return ['status' => self::WAITING, 'message' => null];
        }

        $handle = $this->acquireLock();

        // Another cron run is still installing.
        if ($handle === null) {
            return ['status' => self::BUSY, 'message' => null];
        }

        try {
            $this->install();
            $this->saveState(['ok' => true, 'lock' => $lock, 'attempted' => $lock, 'message' => 'Packages installed.']);

            return ['status' => self::INSTALLED, 'message' => 'Packages installed.'];
        } catch (Throwable $exception) {
            $message = 'Packages not installed: '.$exception->getMessage();
            $this->saveState(['ok' => false, 'lock' => $state['lock'] ?? null, 'attempted' => $lock, 'message' => $message]);

            return ['status' => self::FAILED, 'message' => $message];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * True when Laravel can be started: the autoloader is there.
     */
    public function canBoot(): bool
    {
        return is_file($this->root.'/vendor/autoload.php');
    }

    /**
     * True when vendor/ holds exactly the packages composer.lock asks for.
     */
    public function upToDate(): bool
    {
        if (! $this->canBoot()) {
            return false;
        }

        $state = self::state($this->root);

        if (($state['ok'] ?? false) === true && ($state['lock'] ?? null) === $this->lockHash()) {
            return true;
        }

        // No state yet (vendor/ was uploaded by hand or installed by the host):
        // compare the installed versions with the lock file.
        if ($this->installedMatchesLock()) {
            $this->saveState(['ok' => true, 'lock' => $this->lockHash(), 'attempted' => $this->lockHash(), 'message' => 'Packages match composer.lock.']);

            return true;
        }

        return false;
    }

    /**
     * The last result, for the admin page.
     *
     * @return array{ok?: bool, at?: string, lock?: string|null, attempted?: string|null, message?: string}
     */
    public static function state(string $root): array
    {
        $path = self::statePath($root);

        if (! is_file($path)) {
            return [];
        }

        $state = json_decode((string) file_get_contents($path), true);

        return is_array($state) ? $state : [];
    }

    public static function statePath(string $root): string
    {
        return $root.'/storage/app/private/deploy-packages.json';
    }

    private function installedMatchesLock(): bool
    {
        $lockFile = $this->root.'/composer.lock';
        $installedFile = $this->root.'/vendor/composer/installed.json';

        if (! is_file($lockFile) || ! is_file($installedFile)) {
            return false;
        }

        $lock = json_decode((string) file_get_contents($lockFile), true);
        $installed = json_decode((string) file_get_contents($installedFile), true);

        if (! is_array($lock) || ! is_array($installed)) {
            return false;
        }

        $versions = [];

        foreach ($installed['packages'] ?? $installed as $package) {
            if (is_array($package) && isset($package['name'], $package['version'])) {
                $versions[$package['name']] = $package['version'];
            }
        }

        $locked = $lock['packages'] ?? [];

        if (! is_array($locked) || $locked === []) {
            return false;
        }

        foreach ($locked as $package) {
            if (! is_array($package) || ($versions[$package['name'] ?? ''] ?? null) !== ($package['version'] ?? false)) {
                return false;
            }
        }

        return true;
    }

    private function install(): void
    {
        if (! function_exists('proc_open')) {
            throw new \RuntimeException('this host does not allow PHP to start a process (proc_open), so Composer cannot run. Upload the vendor folder by hand.');
        }

        $composer = $this->composer ?? $this->findComposer();

        if ($composer === null) {
            throw new \RuntimeException('Composer was not found on the server.');
        }

        // Run Composer with the same PHP as this script: the default `php` of the host can be older.
        $head = (string) @file_get_contents($composer, false, null, 0, 200);
        $command = str_ends_with($composer, '.phar') || str_contains($head, 'php') || str_contains($head, '__HALT_COMPILER')
            ? [$this->php, $composer]
            : [$composer];

        $output = $this->root.'/storage/logs/composer-last.log';
        @mkdir(dirname($output), 0775, true);

        $home = getenv('HOME') ?: '';
        $environment = [
            ...$this->currentEnvironment(),
            'COMPOSER_HOME' => getenv('COMPOSER_HOME') ?: ($home !== '' ? $home.'/.composer' : $this->root.'/storage/app/private/composer'),
            'COMPOSER_MEMORY_LIMIT' => '-1',
            'COMPOSER_ALLOW_SUPERUSER' => '1',
            'PATH' => dirname($this->php).PATH_SEPARATOR.(getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'),
        ];

        $process = proc_open(
            [...$command, 'install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--no-progress'],
            [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => ['file', $output, 'w'], 2 => ['file', $output, 'a']],
            $pipes,
            $this->root,
            $environment,
        );

        if (! is_resource($process)) {
            throw new \RuntimeException('Composer could not be started.');
        }

        $started = time();

        do {
            usleep(200_000);
            $status = proc_get_status($process);

            if ($status['running'] && time() - $started > self::TIMEOUT_SECONDS) {
                proc_terminate($process);
                proc_close($process);

                throw new \RuntimeException('Composer was stopped after '.(self::TIMEOUT_SECONDS / 60).' minutes.');
            }
        } while ($status['running']);

        proc_close($process);

        if ($status['exitcode'] !== 0) {
            throw new \RuntimeException('composer install: '.trim(substr((string) @file_get_contents($output), -500)));
        }

        if (! $this->canBoot()) {
            throw new \RuntimeException('composer install finished, but vendor/autoload.php is missing.');
        }
    }

    /**
     * Composer 2 first: on some hosts plain `composer` is still version 1.
     */
    private function findComposer(): ?string
    {
        $home = getenv('HOME') ?: '';
        $directories = array_filter([
            $this->root,
            ...explode(PATH_SEPARATOR, getenv('PATH') ?: ''),
            '/usr/local/bin',
            '/usr/bin',
            '/opt/alt/php83/usr/bin',
            $home !== '' ? $home.'/bin' : null,
        ]);

        foreach (['composer.phar', 'composer2', 'composer'] as $name) {
            foreach ($directories as $directory) {
                $candidate = rtrim((string) $directory, '/').'/'.$name;

                if (@is_file($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * proc_open replaces the whole environment, so pass the current one along.
     *
     * @return array<string, string>
     */
    private function currentEnvironment(): array
    {
        return getenv();
    }

    private function lockHash(): string
    {
        $path = $this->root.'/composer.lock';

        return is_file($path) ? (string) sha1_file($path) : '';
    }

    /**
     * One run at a time: composer needs longer than the minute between two cron runs.
     *
     * @return resource|null
     */
    private function acquireLock()
    {
        $path = $this->root.'/storage/framework/packages.lock';
        @mkdir(dirname($path), 0775, true);

        $handle = @fopen($path, 'c');

        if ($handle === false) {
            return null;
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /**
     * @param  array{ok: bool, lock: string|null, attempted: string|null, message: string}  $state
     */
    private function saveState(array $state): void
    {
        $path = self::statePath($this->root);
        @mkdir(dirname($path), 0775, true);

        file_put_contents($path, json_encode([...$state, 'at' => date(DATE_ATOM)], JSON_PRETTY_PRINT));
    }
}
