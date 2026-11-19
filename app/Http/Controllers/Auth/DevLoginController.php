<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Local-only shortcut: the route is only registered in the local environment.
 */
class DevLoginController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        // Second lock on the door, in case the route ever ends up in a
        // cached route file that is used outside local development.
        abort_unless(app()->environment('local'), 404);

        $user = User::firstOrCreate(
            ['github_id' => 1],
            ['github_username' => 'dev-user', 'name' => 'Dev User'],
        );

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return to_route('dashboard');
    }
}
