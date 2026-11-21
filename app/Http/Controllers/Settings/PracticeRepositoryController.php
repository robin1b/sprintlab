<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdatePracticeRepositoryRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class PracticeRepositoryController extends Controller
{
    public function update(UpdatePracticeRepositoryRequest $request): RedirectResponse
    {
        $repository = $request->repository();

        $request->user()->update(['practice_repository_id' => $repository->id]);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Sprintlab now uses {$repository->full_name}."]);

        return back();
    }
}
