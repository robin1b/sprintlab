<?php

namespace Tests\Feature\GitHub;

use App\Enums\PullRequestState;
use App\Enums\ScenarioAction;
use App\Enums\TicketStatus;
use App\Jobs\OpenColleaguePullRequest;
use App\Jobs\ReviewPullRequest;
use App\Models\PullRequest;
use App\Models\User;
use App\Services\GitHub\ColleaguePullRequestService;
use App\Services\Sprint\SprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\Support\ColleaguePullRequestFixtures;
use Tests\Support\GitHubFixtures;
use Tests\Support\SprintFixtures;
use Tests\TestCase;

class ColleaguePullRequestServiceTest extends TestCase
{
    use ColleaguePullRequestFixtures, GitHubFixtures, RefreshDatabase, SprintFixtures;

    public function test_it_creates_the_branch_writes_the_files_and_opens_the_pull_request(): void
    {
        $this->fakeGitHubPullRequest();
        ['user' => $user, 'ticket' => $ticket, 'event' => $event] = $this->createPullRequestEvent();
        $repository = $this->connectRepository($user);
        $ticket->update(['status' => TicketStatus::InProgress]);

        $pullRequest = app(ColleaguePullRequestService::class)->open($ticket, $event);

        $branch = $this->githubRequests('POST', '/git/refs');
        $this->assertCount(1, $branch);
        $this->assertSame('refs/heads/wk-4-repair-intake', $branch[0]['ref']);
        $this->assertSame(str_repeat('c', 40), $branch[0]['sha']);

        $writes = $this->githubRequests('PUT', '/contents/app/Http/Controllers/RepairController.php');
        $this->assertCount(1, $writes);
        $this->assertSame(base64_encode("<?php\n// secret planted problem\n"), $writes[0]['content']);
        $this->assertSame('wk-4-repair-intake', $writes[0]['branch']);
        $this->assertSame('WK-4: repair intake', $writes[0]['message']);
        $this->assertArrayNotHasKey('sha', $writes[0]->data());
        $this->assertCount(1, $this->githubRequests('PUT', '/contents/routes/repairs.php'));

        $opened = $this->githubRequests('POST', '/pulls');
        $this->assertCount(1, $opened);
        $this->assertSame('WK-4: repair intake', $opened[0]['title']);
        $this->assertSame("**Josh** (Developer)\n\nAdds the repair intake form.", $opened[0]['body']);
        $this->assertSame('wk-4-repair-intake', $opened[0]['head']);
        $this->assertSame('main', $opened[0]['base']);

        $this->assertNotNull($pullRequest);
        $this->assertSame($repository->id, $pullRequest->repository_id);
        $this->assertSame(31, $pullRequest->number);
        $this->assertSame('sprintlab[bot]', $pullRequest->author_login);
        $this->assertSame('wk-4-repair-intake', $pullRequest->head_ref);
        $this->assertSame(str_repeat('b', 40), $pullRequest->head_sha);
        $this->assertSame('main', $pullRequest->base_ref);
        $this->assertSame(PullRequestState::Open, $pullRequest->state);

        $ticket->refresh();
        $this->assertSame($pullRequest->id, $ticket->pull_request_id);
        $this->assertSame(TicketStatus::InProgress, $ticket->status);
    }

    public function test_the_commit_message_is_used_when_given(): void
    {
        $this->fakeGitHubPullRequest();
        ['user' => $user, 'ticket' => $ticket, 'event' => $event] = $this->createPullRequestEvent(['commit_message' => 'Add repair intake']);
        $this->connectRepository($user);

        app(ColleaguePullRequestService::class)->open($ticket, $event);

        $this->assertSame('Add repair intake', $this->githubRequests('PUT', '/contents/routes/repairs.php')[0]['message']);
    }

    public function test_an_existing_branch_is_reused(): void
    {
        $this->fakeGitHubPullRequest(branchExists: true);
        ['user' => $user, 'ticket' => $ticket, 'event' => $event] = $this->createPullRequestEvent();
        $this->connectRepository($user);

        $pullRequest = app(ColleaguePullRequestService::class)->open($ticket, $event);

        $this->assertCount(0, $this->githubRequests('POST', '/git/refs'));
        $this->assertCount(1, $this->githubRequests('PUT', '/contents/app/Http/Controllers/RepairController.php'));
        $this->assertCount(1, $this->githubRequests('PUT', '/contents/routes/repairs.php'));
        $this->assertCount(1, $this->githubRequests('POST', '/pulls'));
        $this->assertNotNull($pullRequest);
    }

    public function test_an_existing_open_pull_request_is_reused_without_writing_files(): void
    {
        $this->fakeGitHubPullRequest(branchExists: true, openPullRequest: true);
        ['user' => $user, 'ticket' => $ticket, 'event' => $event] = $this->createPullRequestEvent();
        $this->connectRepository($user);

        $pullRequest = app(ColleaguePullRequestService::class)->open($ticket, $event);

        $this->assertCount(0, $this->githubRequests('PUT', '/contents/routes/repairs.php'));
        $this->assertCount(0, $this->githubRequests('POST', '/pulls'));
        $this->assertCount(0, $this->githubRequests('POST', '/git/refs'));
        $this->assertSame(31, $pullRequest?->number);
        $this->assertSame($pullRequest?->id, $ticket->fresh()->pull_request_id);
        $this->assertSame('wk-4-repair-intake', $pullRequest->head_ref);
    }

    public function test_a_file_that_already_exists_is_updated_with_its_sha(): void
    {
        $this->fakeGitHubPullRequest(branchExists: true, existingFiles: ['routes/repairs.php']);
        ['user' => $user, 'ticket' => $ticket, 'event' => $event] = $this->createPullRequestEvent();
        $this->connectRepository($user);

        app(ColleaguePullRequestService::class)->open($ticket, $event);

        $this->assertSame('existing-sha', $this->githubRequests('PUT', '/contents/routes/repairs.php')[0]['sha']);
        $this->assertArrayNotHasKey('sha', $this->githubRequests('PUT', '/contents/app/Http/Controllers/RepairController.php')[0]->data());
    }

    public function test_without_a_repository_nothing_happens(): void
    {
        Http::fake();
        ['ticket' => $ticket, 'event' => $event] = $this->createPullRequestEvent();

        $this->assertNull(app(ColleaguePullRequestService::class)->open($ticket, $event));

        Http::assertNothingSent();
        $this->assertNull($ticket->fresh()->pull_request_id);
        $this->assertSame(0, PullRequest::count());
    }

    public function test_a_suspended_installation_is_left_alone(): void
    {
        Http::fake();
        ['user' => $user, 'ticket' => $ticket, 'event' => $event] = $this->createPullRequestEvent();
        $this->connectRepository($user, suspended: true);

        $this->assertNull(app(ColleaguePullRequestService::class)->open($ticket, $event));

        Http::assertNothingSent();
        $this->assertNull($ticket->fresh()->pull_request_id);
    }

    public function test_a_suspension_after_linking_is_respected(): void
    {
        Http::fake();
        ['user' => $user, 'sprint' => $sprint, 'ticket' => $ticket, 'event' => $event] = $this->createPullRequestEvent();
        $repository = $this->connectRepository($user);
        $sprint->update(['repository_id' => $repository->id]);
        $repository->installation->update(['suspended_at' => now()]);

        $this->assertNull(app(ColleaguePullRequestService::class)->open($ticket->fresh(), $event));

        Http::assertNothingSent();
    }

    public function test_a_second_call_does_nothing(): void
    {
        $this->fakeGitHubPullRequest();
        ['user' => $user, 'ticket' => $ticket, 'event' => $event] = $this->createPullRequestEvent();
        $this->connectRepository($user);
        $service = app(ColleaguePullRequestService::class);

        $first = $service->open($ticket, $event);
        Http::fake();
        $second = $service->open($ticket->fresh(), $event);

        Http::assertNothingSent();
        $this->assertSame($first?->id, $second?->id);
        $this->assertSame(1, PullRequest::count());
    }

    public function test_another_action_is_ignored(): void
    {
        Http::fake();
        ['user' => $user, 'ticket' => $ticket, 'event' => $event] = $this->createPullRequestEvent();
        $this->connectRepository($user);
        $event->update(['action' => ScenarioAction::Message]);

        $this->assertNull(app(ColleaguePullRequestService::class)->open($ticket, $event));

        Http::assertNothingSent();
    }

    public function test_the_job_opens_the_pull_request(): void
    {
        $this->fakeGitHubPullRequest();
        ['user' => $user, 'ticket' => $ticket, 'event' => $event] = $this->createPullRequestEvent();
        $this->connectRepository($user);

        (new OpenColleaguePullRequest($ticket->id, $event->id))->handle(app(ColleaguePullRequestService::class));

        $this->assertNotNull($ticket->fresh()->pull_request_id);
    }

    public function test_the_job_ignores_records_that_are_gone(): void
    {
        Http::fake();

        (new OpenColleaguePullRequest(999, 999))->handle(app(ColleaguePullRequestService::class));

        Http::assertNothingSent();
    }

    public function test_the_webhook_that_follows_keeps_the_link_and_queues_no_review(): void
    {
        Bus::fake([ReviewPullRequest::class]);
        $this->fakeGitHubPullRequest();
        ['user' => $user, 'ticket' => $ticket, 'event' => $event] = $this->createPullRequestEvent();
        $this->connectRepository($user);
        $ticket->update(['status' => TicketStatus::InProgress]);
        $pullRequest = app(ColleaguePullRequestService::class)->open($ticket, $event);

        $payload = $this->pullRequestPayload('opened', [
            'number' => 31,
            'title' => 'WK-4: repair intake',
            'user' => ['login' => 'sprintlab[bot]', 'type' => 'Bot'],
            'head' => ['ref' => 'wk-4-repair-intake', 'sha' => str_repeat('b', 40)],
        ]);
        $this->githubWebhook('pull_request', $payload)->assertSuccessful();
        $this->artisan('queue:work', ['--stop-when-empty' => true, '--once' => false]);

        $ticket->refresh();
        $this->assertSame($pullRequest?->id, $ticket->pull_request_id);
        $this->assertSame(TicketStatus::InProgress, $ticket->status);
        $this->assertSame(1, PullRequest::count());
        Bus::assertNotDispatched(ReviewPullRequest::class);
    }

    public function test_starting_a_sprint_links_the_connected_repository(): void
    {
        $user = User::factory()->create();
        $repository = $this->connectRepository($user);

        $sprint = $this->startDemoSprint($user);

        $this->assertSame($repository->id, $sprint->repository_id);
        $this->assertTrue($sprint->repository->is($repository));
    }

    public function test_starting_a_sprint_without_github_leaves_the_repository_empty(): void
    {
        $this->assertNull($this->startDemoSprint()->repository_id);
    }

    public function test_the_newest_repository_that_is_not_suspended_is_used(): void
    {
        $user = User::factory()->create();
        $older = $this->connectRepository($user);
        $newer = $this->connectRepository($user, githubId: 7002, fullName: 'robin/other');
        $other = User::factory()->create();
        $this->connectRepository($other, githubId: 7003, fullName: 'someone/else');

        $this->assertSame($newer->id, $this->startDemoSprint($user)->repository_id);
        $this->assertNotSame($older->id, $newer->id);
    }

    public function test_a_suspended_installation_is_not_linked_at_the_start(): void
    {
        $user = User::factory()->create();
        $this->connectRepository($user, suspended: true);

        $this->assertNull($this->startDemoSprint($user)->repository_id);
    }

    public function test_the_repository_is_linked_later_when_github_is_connected_after_the_start(): void
    {
        $user = User::factory()->create();
        $sprint = $this->startDemoSprint($user);
        $service = app(SprintService::class);

        $this->assertNull($service->repositoryFor($sprint));
        $this->assertNull($sprint->fresh()->repository_id);

        $repository = $this->connectRepository($user);

        $this->assertTrue($service->repositoryFor($sprint)?->is($repository));
        $this->assertSame($repository->id, $sprint->fresh()->repository_id);
    }

    public function test_a_linked_repository_is_kept(): void
    {
        $user = User::factory()->create();
        $first = $this->connectRepository($user);
        $sprint = $this->startDemoSprint($user);
        $this->connectRepository($user, githubId: 7002, fullName: 'robin/other');

        $this->assertTrue(app(SprintService::class)->repositoryFor($sprint->fresh())?->is($first));
    }
}
