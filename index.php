<?php

/*
 * On the server the project root is the web root (the host cannot point a
 * domain at public/), so this file starts Laravel there. Locally the web
 * server uses public/index.php. The .htaccess next to this file blocks
 * everything that must not be public. See docs/deployment.md.
 */

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
