<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

require __DIR__.'/auth.php';
require __DIR__.'/app.php';
require __DIR__.'/settings.php';
require __DIR__.'/webhooks.php';
