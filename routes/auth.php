<?php

use App\Http\Controllers\Auth\DevLoginController;
use App\Http\Controllers\Auth\GitHubController;
use App\Http\Controllers\Auth\LogoutController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('guest')->group(function () {
    Route::get('login', fn () => Inertia::render('auth/Login', [
        'devLogin' => app()->environment('local'),
    ]))->name('login');

    Route::get('auth/github/redirect', [GitHubController::class, 'redirect'])->name('auth.github.redirect');
    Route::get('auth/github/callback', [GitHubController::class, 'callback'])->name('auth.github.callback');
});

Route::post('logout', LogoutController::class)->middleware('auth')->name('logout');

// Local development only: never registered in any other environment.
if (app()->environment('local')) {
    Route::get('dev/login', DevLoginController::class)->name('dev.login');
}
