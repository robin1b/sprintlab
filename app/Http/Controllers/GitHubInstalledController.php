<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class GitHubInstalledController extends Controller
{
    /**
     * GitHub sends the user here after installing the app (the app's Setup URL).
     * Nothing in the request is trusted: the webhooks tell what was installed.
     */
    public function __invoke(): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => 'GitHub is connecting your repository. This takes a few seconds.']);

        return to_route('dashboard');
    }
}
