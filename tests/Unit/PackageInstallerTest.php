<?php

namespace Tests\Unit;

use App\Support\Deploy\PackageInstaller;
use PHPUnit\Framework\TestCase;

/**
 * The installer runs before Laravel on the server, so it is tested without
 * Laravel too: a temporary project folder and a fake Composer.
 */
class PackageInstallerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/sprintlab-packages-'.bin2hex(random_bytes(6));

        mkdir($this->root.'/storage/app/private', 0775, true);
        mkdir($this->root.'/storage/framework', 0775, true);
        mkdir($this->root.'/storage/logs', 0775, true);

        $this->writeLock(['acme/tool' => '1.0.0']);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);

        parent::tearDown();
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && ! is_link($path)) {
            foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
                $this->remove($path.'/'.$entry);
            }

            rmdir($path);

            return;
        }

        @unlink($path);
    }

    public function test_nothing_happens_when_vendor_matches_the_lock_file(): void
    {
        $this->writeVendor(['acme/tool' => '1.0.0']);

        $result = $this->installer($this->composerThatFails())->run();

        $this->assertSame(PackageInstaller::CURRENT, $result['status']);
        $this->assertTrue(PackageInstaller::state($this->root)['ok']);
    }

    public function test_the_first_run_installs_the_packages(): void
    {
        $result = $this->installer($this->composerThatWorks())->run();

        $this->assertSame(PackageInstaller::INSTALLED, $result['status']);
        $this->assertSame('Packages installed.', $result['message']);
        $this->assertFileExists($this->root.'/vendor/autoload.php');
        $this->assertStringContainsString(
            'install --no-dev --optimize-autoloader --no-interaction --no-progress',
            (string) file_get_contents($this->root.'/composer-arguments.txt'),
        );
        $this->assertTrue(PackageInstaller::state($this->root)['ok']);
    }

    public function test_a_changed_lock_file_installs_again_and_then_rests(): void
    {
        $this->writeVendor(['acme/tool' => '1.0.0']);
        $installer = $this->installer($this->composerThatWorks());

        $this->assertSame(PackageInstaller::CURRENT, $installer->run()['status']);

        $this->writeLock(['acme/tool' => '1.1.0']);

        $this->assertSame(PackageInstaller::INSTALLED, $installer->run()['status']);
        $this->assertSame(PackageInstaller::CURRENT, $installer->run()['status']);
    }

    public function test_a_failure_is_recorded_and_not_retried_every_minute(): void
    {
        $installer = $this->installer($this->composerThatFails());

        $result = $installer->run();

        $this->assertSame(PackageInstaller::FAILED, $result['status']);
        $this->assertStringContainsString('Your requirements could not be resolved', (string) $result['message']);
        $this->assertFalse(PackageInstaller::state($this->root)['ok']);
        $this->assertFalse($installer->canBoot());

        // A minute later: wait.
        $this->assertSame(PackageInstaller::WAITING, $installer->run()['status']);

        // After the waiting time: try again.
        $this->ageState(PackageInstaller::RETRY_AFTER_SECONDS + 60);
        $this->assertSame(PackageInstaller::FAILED, $installer->run()['status']);
    }

    public function test_a_failed_update_keeps_the_site_running_on_the_old_packages(): void
    {
        $this->writeVendor(['acme/tool' => '1.0.0']);
        $this->writeLock(['acme/tool' => '2.0.0']);

        $installer = $this->installer($this->composerThatFails());

        $this->assertSame(PackageInstaller::FAILED, $installer->run()['status']);
        $this->assertTrue($installer->canBoot());
    }

    public function test_a_second_run_waits_while_the_first_is_still_installing(): void
    {
        $handle = fopen($this->root.'/storage/framework/packages.lock', 'c');
        $this->assertNotFalse($handle);
        flock($handle, LOCK_EX);

        try {
            $result = $this->installer($this->composerThatWorks())->run();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $this->assertSame(PackageInstaller::BUSY, $result['status']);
        $this->assertFileDoesNotExist($this->root.'/vendor/autoload.php');
    }

    public function test_composer_that_is_missing_is_reported(): void
    {
        $result = (new PackageInstaller($this->root, $this->root.'/nowhere/composer.phar'))->run();

        $this->assertSame(PackageInstaller::FAILED, $result['status']);
    }

    private function installer(string $composer): PackageInstaller
    {
        return new PackageInstaller($this->root, $composer);
    }

    /**
     * A fake Composer: writes vendor/ the way the real one would.
     */
    private function composerThatWorks(): string
    {
        $path = $this->root.'/composer.phar';

        file_put_contents($path, <<<'PHP'
            <?php
            $root = getcwd();
            @mkdir($root.'/vendor/composer', 0775, true);
            file_put_contents($root.'/vendor/autoload.php', '<?php');
            file_put_contents($root.'/composer-arguments.txt', implode(' ', array_slice($argv, 1)));
            $lock = json_decode(file_get_contents($root.'/composer.lock'), true);
            file_put_contents($root.'/vendor/composer/installed.json', json_encode(['packages' => $lock['packages']]));
            echo "Generating optimized autoload files\n";
            PHP);

        return $path;
    }

    private function composerThatFails(): string
    {
        $path = $this->root.'/broken-composer.phar';

        file_put_contents($path, <<<'PHP'
            <?php
            fwrite(STDERR, "Your requirements could not be resolved to an installable set of packages.\n");
            exit(2);
            PHP);

        return $path;
    }

    /**
     * @param  array<string, string>  $packages
     */
    private function writeLock(array $packages): void
    {
        file_put_contents($this->root.'/composer.lock', json_encode(['packages' => $this->packageList($packages)]));
    }

    /**
     * @param  array<string, string>  $packages
     */
    private function writeVendor(array $packages): void
    {
        @mkdir($this->root.'/vendor/composer', 0775, true);

        file_put_contents($this->root.'/vendor/autoload.php', '<?php');
        file_put_contents($this->root.'/vendor/composer/installed.json', json_encode(['packages' => $this->packageList($packages)]));
    }

    /**
     * @param  array<string, string>  $packages
     * @return list<array{name: string, version: string}>
     */
    private function packageList(array $packages): array
    {
        $list = [];

        foreach ($packages as $name => $version) {
            $list[] = ['name' => $name, 'version' => $version];
        }

        return $list;
    }

    private function ageState(int $seconds): void
    {
        $path = PackageInstaller::statePath($this->root);
        $state = json_decode((string) file_get_contents($path), true);
        $state['at'] = date(DATE_ATOM, time() - $seconds);

        file_put_contents($path, json_encode($state));
    }
}
