<?php

use App\Http\Controllers\PrivacyController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');
Route::get('privacy', PrivacyController::class)->name('privacy');

require __DIR__.'/auth.php';
require __DIR__.'/app.php';
require __DIR__.'/settings.php';
