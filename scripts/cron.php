<?php

/**
 * The one cron job of Sprintlab, every minute:
 *
 *   /opt/alt/php83/usr/bin/php /path/to/sprintlab/scripts/cron.php
 *
 * 1. Installs the Composer packages when composer.lock changed (or on the very
 *    first run, when vendor/ does not exist yet). This works before Laravel can
 *    start, so a host without SSH never needs a manual `composer install`.
 * 2. Starts Laravel's scheduler, which empties the queue, fires sprint events
 *    and finishes a deployment (`site:deploy`: migrations, scenarios, caches).
 *
 * See docs/deployment.md.
 */

use App\Support\Deploy\PackageInstaller;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);

    exit;
}

$root = dirname(__DIR__);

require $root.'/app/Support/Deploy/PackageInstaller.php';

$installer = new PackageInstaller($root);
$result = $installer->run();

if ($result['message'] !== null) {
    $line = '['.date('Y-m-d H:i:s').'] '.$result['message'].PHP_EOL;

    @mkdir($root.'/storage/logs', 0775, true);
    @file_put_contents($root.'/storage/logs/deploy.log', $line, FILE_APPEND);

    echo $line;
}

// Another run is installing right now, or there is no vendor/ to start Laravel with.
if ($result['status'] === PackageInstaller::BUSY || ! $installer->canBoot()) {
    exit($result['status'] === PackageInstaller::FAILED ? 1 : 0);
}

$_SERVER['argv'] = $argv = ['artisan', 'schedule:run'];
$_SERVER['argc'] = $argc = 2;

require $root.'/artisan';
