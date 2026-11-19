<?php

use App\Http\Controllers\Webhooks\GitHubWebhookController;
use App\Http\Middleware\VerifyGitHubSignature;
use Illuminate\Support\Facades\Route;

// Called by GitHub, not by a browser: loaded without session or CSRF
// middleware (see bootstrap/app.php).
Route::post('webhooks/github', GitHubWebhookController::class)
    ->middleware(VerifyGitHubSignature::class)
    ->name('webhooks.github');
