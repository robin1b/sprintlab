<?php

use Illuminate\Support\Facades\Route;

// Pages of the signed-in app (dashboard, board, chat).
Route::middleware(['auth'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});
