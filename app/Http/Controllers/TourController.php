<?php

namespace App\Http\Controllers;

use App\Enums\TourPart;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Remembers which parts of the guided tour a user has seen, so a part starts
 * by itself only once, on any device.
 */
class TourController extends Controller
{
    public function finish(Request $request, TourPart $part): RedirectResponse
    {
        $user = $request->user();
        $done = $user->tours_done ?? [];

        if (! in_array($part->value, $done, true)) {
            $done[] = $part->value;
            $user->update(['tours_done' => $done]);
        }

        return back();
    }

    public function restart(Request $request): RedirectResponse
    {
        $request->user()->update(['tours_done' => []]);

        return to_route('dashboard');
    }
}
