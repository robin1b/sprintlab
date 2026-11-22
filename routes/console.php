<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The host has no queue worker running all the time. The scheduler empties
// the queue every minute instead (webhooks, AI reviews).
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=2')
    ->everyMinute()
    ->withoutOverlapping(5);

// Scenario events with a delay (a colleague writes ten minutes later) are
// fired by the scheduler, because nothing else runs between requests.
Schedule::command('sprintlab:tick')
    ->everyMinute()
    ->withoutOverlapping(5);

// site:deploy is not scheduled here: scripts/cron.php runs it every minute,
// before the scheduler, so a fresh install has its tables before the
// scheduler needs the cache (see docs/deployment.md).

// Removes what is only kept for a while, such as old webhook messages from
// GitHub (promised on the privacy page).
Schedule::command('model:prune')->daily();
