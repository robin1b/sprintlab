<?php

namespace App\Http\Middleware;

use App\Models\Sprint;
use App\Services\Sprint\SprintService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'github_username' => $request->user()->github_username,
                    'avatar' => $request->user()->avatar_url,
                ] : null,
            ],
            'flash' => [
                'error' => $request->session()->get('error'),
                'success' => $request->session()->get('success'),
            ],
            'sprint' => fn () => $this->sprintProps($request),
            'unreadMessages' => fn () => $this->unreadMessages($request),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * The user's current sprint, in the small shape the top bar needs.
     *
     * @return array<string, mixed>|null
     */
    private function sprintProps(Request $request): ?array
    {
        $sprint = $this->currentSprint($request);

        if ($sprint === null) {
            return null;
        }

        return [
            'id' => $sprint->id,
            'status' => $sprint->status->value,
            'scenario_title' => $sprint->scenario->title,
            'current_day' => $sprint->current_day,
            'duration_days' => $sprint->scenario->duration_days,
            'deadline_at' => $sprint->deadline_at->toIso8601String(),
            'is_overdue' => $sprint->isOverdue(),
        ];
    }

    private function unreadMessages(Request $request): int
    {
        return $this->currentSprint($request)?->messages()
            ->where('from_user', false)
            ->whereNull('read_at')
            ->count() ?? 0;
    }

    /**
     * Both shared props need the sprint: look it up once per request.
     */
    private function currentSprint(Request $request): ?Sprint
    {
        $user = $request->user();

        if ($user === null) {
            return null;
        }

        if (! $request->attributes->has('current_sprint')) {
            $request->attributes->set('current_sprint', app(SprintService::class)->currentFor($user)?->load('scenario'));
        }

        return $request->attributes->get('current_sprint');
    }
}
