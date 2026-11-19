<?php

namespace Tests\Support;

use App\Enums\Colleague;
use App\Enums\ScenarioAction;
use App\Enums\ScenarioTrigger;
use App\Enums\TicketType;
use App\Models\GitHubInstallation;
use App\Models\Repository;
use App\Models\Scenario;
use App\Models\ScenarioEvent;
use App\Models\ScenarioTicket;
use App\Models\Sprint;
use App\Models\SprintEvent;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Helpers for tests about a colleague opening a pull request in the user's
 * repository. The test class must also use GitHubFixtures.
 */
trait ColleaguePullRequestFixtures
{
    /**
     * A sprint of a user with a review ticket (WK-4) and the event that opens its pull request.
     *
     * @param  array<string, mixed>  $payload  values that override the event payload
     * @return array{user: User, sprint: Sprint, ticket: Ticket, event: ScenarioEvent}
     */
    protected function createPullRequestEvent(array $payload = []): array
    {
        $user = User::factory()->create(['github_id' => 4242, 'name' => 'Robin Broos']);
        $scenario = Scenario::factory()->create();
        $scenarioTicket = ScenarioTicket::factory()->for($scenario)->create(['key' => 'WK-4', 'type' => TicketType::Review]);
        $sprint = Sprint::factory()->for($user)->for($scenario)->create();
        $ticket = Ticket::factory()->for($sprint)->for($scenarioTicket)->create(['key' => 'WK-4', 'type' => TicketType::Review]);

        $event = ScenarioEvent::factory()->for($scenario)->create([
            'key' => 'josh-opens-pr',
            'trigger' => ScenarioTrigger::DayStarted,
            'ticket_key' => 'WK-4',
            'action' => ScenarioAction::OpenPullRequest,
            'colleague' => Colleague::Josh,
            'payload' => array_replace([
                'branch' => 'wk-4-repair-intake',
                'title' => 'WK-4: repair intake',
                'description' => 'Adds the repair intake form.',
                'files' => [
                    ['path' => 'app/Http/Controllers/RepairController.php', 'content' => "<?php\n// secret planted problem\n"],
                    ['path' => 'routes/repairs.php', 'content' => "<?php\n"],
                ],
                'body' => 'Can you review my pull request, {first_name}?',
            ], $payload),
        ]);

        return compact('user', 'sprint', 'ticket', 'event');
    }

    /**
     * Connect the user to the repository robin/workkit through an installation.
     */
    protected function connectRepository(User $user, bool $suspended = false, int $githubId = 7001, string $fullName = 'robin/workkit'): Repository
    {
        $installation = GitHubInstallation::firstOrCreate(
            ['user_id' => $user->id],
            [
                'installation_id' => 9001 + $user->id - 1,
                'account_login' => 'robin',
                'account_id' => 4242,
                'suspended_at' => $suspended ? now() : null,
            ],
        );

        return Repository::create([
            'github_id' => $githubId,
            'github_installation_id' => $installation->id,
            'full_name' => $fullName,
            'private' => true,
        ]);
    }

    /**
     * Mark an event as fired for a sprint, as the engine does.
     */
    protected function fireEvent(Sprint $sprint, ScenarioEvent $event): SprintEvent
    {
        return SprintEvent::create([
            'sprint_id' => $sprint->id,
            'scenario_event_id' => $event->id,
            'due_at' => now()->subMinute(),
            'fired_at' => now(),
        ]);
    }

    /**
     * Fake GitHub for opening a pull request in robin/workkit.
     *
     * @param  bool  $branchExists  the colleague's branch is already there
     * @param  list<string>  $existingFiles  paths that already exist on that branch
     * @param  bool  $openPullRequest  an open pull request from that branch exists
     */
    protected function fakeGitHubPullRequest(bool $branchExists = false, array $existingFiles = [], bool $openPullRequest = false): void
    {
        $this->configureGitHubApp();
        $pullRequest = ['number' => 31, 'head' => ['sha' => str_repeat('b', 40)], 'user' => ['login' => 'sprintlab[bot]']];

        Http::fake(function (Request $request) use ($branchExists, $existingFiles, $openPullRequest, $pullRequest) {
            $url = $request->url();
            $method = $request->method();
            $path = (string) parse_url($url, PHP_URL_PATH);

            return match (true) {
                str_contains($url, '/access_tokens') => Http::response(['token' => 'installation-token', 'expires_at' => now()->addHour()->toIso8601String()], 201),
                $method === 'GET' && $path === '/repos/robin/workkit' => Http::response(['default_branch' => 'main']),
                $method === 'GET' && $path === '/repos/robin/workkit/pulls' => Http::response($openPullRequest ? [$pullRequest] : []),
                $method === 'GET' && $path === '/repos/robin/workkit/git/ref/heads/main' => Http::response(['object' => ['sha' => str_repeat('c', 40)]]),
                $method === 'GET' && $path === '/repos/robin/workkit/git/ref/heads/wk-4-repair-intake' => $branchExists
                    ? Http::response(['object' => ['sha' => str_repeat('d', 40)]])
                    : Http::response(['message' => 'Not Found'], 404),
                $method === 'POST' && $path === '/repos/robin/workkit/git/refs' => Http::response([], 201),
                $method === 'GET' && str_starts_with($path, '/repos/robin/workkit/contents/') => in_array(substr($path, strlen('/repos/robin/workkit/contents/')), $existingFiles, true)
                    ? Http::response(['sha' => 'existing-sha'])
                    : Http::response(['message' => 'Not Found'], 404),
                $method === 'PUT' && str_starts_with($path, '/repos/robin/workkit/contents/') => Http::response([], 201),
                $method === 'POST' && $path === '/repos/robin/workkit/pulls' => Http::response($pullRequest, 201),
                default => Http::response(['message' => 'Unexpected call'], 500),
            };
        });
    }

    /**
     * The requests Sprintlab sent to GitHub with a method to a path under the repository.
     *
     * @return list<Request>
     */
    protected function githubRequests(string $method, string $path): array
    {
        $found = [];

        foreach (Http::recorded() as [$request]) {
            if ($request->method() === $method && parse_url($request->url(), PHP_URL_PATH) === '/repos/robin/workkit'.$path) {
                $found[] = $request;
            }
        }

        return $found;
    }
}
