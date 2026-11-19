<?php

use App\Http\Controllers\BoardController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SprintController;
use App\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;

// Pages of the signed-in app (overview, board, chat) and what you can do there.
Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::post('sprints', [SprintController::class, 'store'])->name('sprints.store');
    Route::post('sprints/{sprint}/pause', [SprintController::class, 'pause'])->name('sprints.pause');
    Route::post('sprints/{sprint}/resume', [SprintController::class, 'resume'])->name('sprints.resume');

    Route::get('board', BoardController::class)->name('board');
    Route::patch('tickets/{ticket}', [TicketController::class, 'update'])->name('tickets.update');

    Route::get('chat/{colleague?}', ChatController::class)->name('chat');
});
