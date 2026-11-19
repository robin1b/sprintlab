<?php

namespace App\Http\Controllers;

use App\Http\Requests\Sprint\StartSprintRequest;
use App\Models\Scenario;
use App\Models\Sprint;
use App\Services\Sprint\SprintService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SprintController extends Controller
{
    public function __construct(private readonly SprintService $sprints) {}

    public function store(StartSprintRequest $request): RedirectResponse
    {
        $scenario = Scenario::findOrFail($request->integer('scenario_id'));

        try {
            $this->sprints->start($request->user(), $scenario);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return to_route('board');
    }

    public function pause(Request $request, Sprint $sprint): RedirectResponse
    {
        Gate::authorize('pause', $sprint);

        try {
            $this->sprints->pause($sprint);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back();
    }

    public function resume(Request $request, Sprint $sprint): RedirectResponse
    {
        Gate::authorize('resume', $sprint);

        try {
            $this->sprints->resume($sprint);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back();
    }
}
