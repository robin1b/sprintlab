<?php

namespace Tests\Feature\GitHub;

use App\Enums\TicketStatus;
use App\Jobs\ReviewPullRequest;
use App\Models\GitHubInstallation;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Models\User;
use App\Services\GitHub\Onboarding;
use App\Services\Sprint\SprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\GitHubFixtures;
use Tests\Support\SprintFixtures;
use Tests\TestCase;

/**
 * Sprintlab only acts on the practice repository, even when the app can see
 * all repositories of the user.
 */
class PracticeRepositoryTest extends TestCase
{
    use GitHubFixtures, RefreshDatabase, SprintFixtures;

    private User $user;

    private GitHubInstallation $installation;

    private Repository $practice;

    private Repository $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['github.app_slug' => 'sprintlab', 'sprintlab.template_repository' => 'robin1b/workkit']);

        $this->user = User::factory()->create(['github_id' => 4242, 'name' => 'Robin Broos']);
        $this->installation = GitHubInstallation::create([
            'installation_id' => 9001, 'user_id' => $this->user->id, 'account_login' => 'robin', 'account_id' => 4242,
        ]);
        $this->practice = Repository::create(['github_id' => 7001, 'github_installation_id' => $this->installation->id, 'full_name' => 'robin/practice', 'private' => true]);
        $this->other = Repository::create(['github_id' => 7002, 'github_installation_id' => $this->installation->id, 'full_name' => 'robin/secret', 'private' => true]);
        $this->user->update(['practice_repository_id' => $this->practice->id]);
    }

    /**
     * @return array<string, mixed>
     */
    private function inOtherRepository(array $payload): array
    {
        $payload['repository'] = ['id' => 7002, 'full_name' => 'robin/secret', 'private' => true, 'owner' => ['login' => 'robin', 'id' => 4242]];

        return $payload;
    }

    public function test_a_pull_request_in_another_repository_is_not_stored_or_reviewed(): void
    {
        Bus::fake([ReviewPullRequest::class]);

        $this->githubWebhook('pull_request', $this->inOtherRepository($this->pullRequestPayload()))->assertStatus(202);

        $this->assertSame(0, PullRequest::count());
        Bus::assertNotDispatched(ReviewPullRequest::class);
    }

    public function test_a_pull_request_in_the_practice_repository_is_still_stored_and_reviewed(): void
    {
        Bus::fake([ReviewPullRequest::class]);

        $this->githubWebhook('pull_request', $this->pullRequestPayload())->assertStatus(202);

        $this->assertSame($this->practice->id, PullRequest::sole()->repository_id);
        Bus::assertDispatchedTimes(ReviewPullRequest::class, 1);
    }

    public function test_the_repository_of_the_current_sprint_counts_over_the_practice_choice(): void
    {
        Bus::fake([ReviewPullRequest::class]);
        $sprint = $this->startDemoSprint($this->user);
        $sprint->update(['repository_id' => $this->other->id]);

        $this->githubWebhook('pull_request', $this->pullRequestPayload(), 'delivery-1');
        $this->assertSame(0, PullRequest::count());

        $this->githubWebhook('pull_request', $this->inOtherRepository($this->pullRequestPayload()), 'delivery-2');
        $this->assertSame(1, PullRequest::count());
    }

    public function test_a_branch_with_a_ticket_key_in_another_repository_does_not_move_the_ticket(): void
    {
        $sprint = $this->startDemoSprint($this->user);
        $payload = [
            'ref' => 'feature/WK-1-double-approval',
            'ref_type' => 'branch',
            'installation' => ['id' => 9001],
            'repository' => ['id' => 7002, 'full_name' => 'robin/secret'],
            'sender' => ['id' => 4242, 'type' => 'User'],
        ];

        $this->githubWebhook('create', $payload, 'delivery-1')->assertStatus(202);
        $this->assertSame(TicketStatus::Todo, $this->ticket($sprint, 'WK-1')->status);

        $payload['repository'] = ['id' => 7001, 'full_name' => 'robin/workkit'];
        $this->githubWebhook('create', $payload, 'delivery-2');
        $this->assertSame(TicketStatus::InProgress, $this->ticket($sprint, 'WK-1')->status);
    }

    public function test_the_repository_selection_is_stored_and_updated(): void
    {
        $payload = $this->installationPayload();
        $payload['installation']['id'] = 9100;
        $payload['installation']['repository_selection'] = 'all';
        $this->githubWebhook('installation', $payload, 'delivery-1');

        $this->assertSame('all', GitHubInstallation::where('installation_id', 9100)->sole()->repository_selection);

        $this->githubWebhook('installation_repositories', [
            'action' => 'removed',
            'installation' => ['id' => 9100, 'account' => ['login' => 'robin', 'id' => 4242]],
            'repository_selection' => 'selected',
            'repositories_added' => [],
            'repositories_removed' => [],
            'sender' => ['id' => 4242],
        ], 'delivery-2');

        $this->assertSame('selected', GitHubInstallation::where('installation_id', 9100)->sole()->repository_selection);
    }

    public function test_with_all_repositories_there_is_no_silent_newest_repository(): void
    {
        $this->user->update(['practice_repository_id' => null]);
        $this->installation->update(['repository_selection' => 'all']);

        $this->assertNull(app(SprintService::class)->connectedRepositoryOf($this->user->fresh()));

        $this->user->update(['practice_repository_id' => $this->other->id]);
        $this->assertSame($this->other->id, app(SprintService::class)->connectedRepositoryOf($this->user->fresh())->id);
    }

    public function test_with_all_repositories_a_repository_named_like_the_template_is_found(): void
    {
        $this->user->update(['practice_repository_id' => null]);
        $this->installation->update(['repository_selection' => 'all']);
        $this->practice->update(['full_name' => 'robin/Workkit']);
        $this->other->touch();

        $this->assertSame($this->practice->id, app(SprintService::class)->connectedRepositoryOf($this->user->fresh())->id);
    }

    public function test_with_selected_repositories_the_newest_is_still_used(): void
    {
        $this->user->update(['practice_repository_id' => null]);
        $this->installation->update(['repository_selection' => 'selected']);

        $this->assertSame($this->other->id, app(SprintService::class)->connectedRepositoryOf($this->user->fresh())->id);

        $this->installation->update(['repository_selection' => null]);
        $this->assertSame($this->other->id, app(SprintService::class)->connectedRepositoryOf($this->user->fresh())->id);
    }

    public function test_the_dashboard_tells_whether_the_app_sees_all_repositories(): void
    {
        $this->actingAs($this->user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('setup.all_repositories', false)
                ->where('setup.configure_url', 'https://github.com/settings/installations/9001'));

        $this->installation->update(['repository_selection' => 'all']);

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('setup.all_repositories', true)
                ->where('setup.configure_url', 'https://github.com/settings/installations/9001'));
    }

    public function test_without_an_installation_there_is_no_configure_url(): void
    {
        $state = app(Onboarding::class)->stateFor(User::factory()->create());

        $this->assertFalse($state['all_repositories']);
        $this->assertNull($state['configure_url']);
    }
}
