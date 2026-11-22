<?php

namespace Tests\Feature\GitHub;

use App\Enums\PullRequestState;
use App\Jobs\ReviewPullRequest;
use App\Models\GitHubInstallation;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Models\User;
use App\Models\WebhookDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Support\GitHubFixtures;
use Tests\TestCase;

/**
 * The queue runs synchronously in tests, so posting a webhook also
 * processes it. Only the review job is faked.
 */
class WebhookHandlerTest extends TestCase
{
    use GitHubFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([ReviewPullRequest::class]);
    }

    public function test_installing_the_app_links_it_to_the_user_and_stores_the_repositories(): void
    {
        $user = User::factory()->create(['github_id' => 4242]);

        $this->githubWebhook('installation', $this->installationPayload())->assertStatus(202);

        $installation = GitHubInstallation::sole();

        $this->assertSame(9001, $installation->installation_id);
        $this->assertSame($user->id, $installation->user_id);
        $this->assertSame('robin', $installation->account_login);
        $this->assertSame('robin/workkit', Repository::sole()->full_name);
        $this->assertNotNull(WebhookDelivery::sole()->processed_at);
    }

    public function test_an_installation_by_an_unknown_github_account_has_no_user(): void
    {
        $this->githubWebhook('installation', $this->installationPayload(senderId: 1));

        $this->assertNull(GitHubInstallation::sole()->user_id);
    }

    public function test_removing_the_app_deletes_the_installation_and_its_repositories(): void
    {
        $this->githubWebhook('installation', $this->installationPayload(), 'delivery-1');
        $this->githubWebhook('installation', $this->installationPayload('deleted'), 'delivery-2');

        $this->assertSame(0, GitHubInstallation::count());
        $this->assertSame(0, Repository::count());
    }

    public function test_suspending_and_unsuspending_is_recorded(): void
    {
        $this->githubWebhook('installation', $this->installationPayload('suspend'), 'delivery-1');
        $this->assertNotNull(GitHubInstallation::sole()->suspended_at);

        $this->githubWebhook('installation', $this->installationPayload('unsuspend'), 'delivery-2');
        $this->assertNull(GitHubInstallation::sole()->suspended_at);
    }

    public function test_repositories_can_be_added_and_removed_later(): void
    {
        $this->githubWebhook('installation', $this->installationPayload(), 'delivery-1');

        $this->githubWebhook('installation_repositories', [
            'action' => 'added',
            'installation' => ['id' => 9001, 'account' => ['login' => 'robin', 'id' => 4242]],
            'repositories_added' => [['id' => 7002, 'full_name' => 'robin/second', 'private' => false]],
            'repositories_removed' => [['id' => 7001, 'full_name' => 'robin/workkit', 'private' => true]],
            'sender' => ['id' => 4242],
        ], 'delivery-2');

        $this->assertSame(['robin/second'], Repository::pluck('full_name')->all());
    }

    public function test_an_opened_pull_request_is_stored_and_sent_for_review(): void
    {
        $this->repositoryOwnerHasAnAccount();

        $this->githubWebhook('pull_request', $this->pullRequestPayload());

        $pullRequest = PullRequest::sole();

        $this->assertSame(12, $pullRequest->number);
        $this->assertSame('robin/workkit', $pullRequest->repository->full_name);
        $this->assertSame(PullRequestState::Open, $pullRequest->state);
        $this->assertSame(str_repeat('a', 40), $pullRequest->head_sha);

        Bus::assertDispatched(
            ReviewPullRequest::class,
            fn (ReviewPullRequest $job) => $job->pullRequestId === $pullRequest->id && $job->headSha === str_repeat('a', 40),
        );
    }

    public function test_a_new_push_updates_the_pull_request_and_asks_for_a_new_review(): void
    {
        $this->repositoryOwnerHasAnAccount();

        $this->githubWebhook('pull_request', $this->pullRequestPayload(), 'delivery-1');
        $this->githubWebhook('pull_request', $this->pullRequestPayload('synchronize', [
            'head' => ['sha' => str_repeat('b', 40)],
        ]), 'delivery-2');

        $this->assertSame(str_repeat('b', 40), PullRequest::sole()->head_sha);
        Bus::assertDispatchedTimes(ReviewPullRequest::class, 2);
    }

    public function test_a_draft_pull_request_is_not_reviewed_until_it_is_ready(): void
    {
        $this->repositoryOwnerHasAnAccount();

        $this->githubWebhook('pull_request', $this->pullRequestPayload('opened', ['draft' => true]), 'delivery-1');
        Bus::assertNotDispatched(ReviewPullRequest::class);

        $this->githubWebhook('pull_request', $this->pullRequestPayload('ready_for_review'), 'delivery-2');
        Bus::assertDispatchedTimes(ReviewPullRequest::class, 1);
    }

    public function test_a_pull_request_opened_by_a_bot_is_not_reviewed(): void
    {
        $this->repositoryOwnerHasAnAccount();

        $this->githubWebhook('pull_request', $this->pullRequestPayload('opened', [
            'user' => ['login' => 'sprintlab[bot]', 'type' => 'Bot'],
        ]));

        $this->assertSame(1, PullRequest::count());
        Bus::assertNotDispatched(ReviewPullRequest::class);
    }

    public function test_closing_and_merging_update_the_state_without_a_review(): void
    {
        $this->repositoryOwnerHasAnAccount();

        $this->githubWebhook('pull_request', $this->pullRequestPayload('closed', ['state' => 'closed']), 'delivery-1');
        $this->assertSame(PullRequestState::Closed, PullRequest::sole()->state);

        $this->githubWebhook('pull_request', $this->pullRequestPayload('closed', ['state' => 'closed', 'merged' => true]), 'delivery-2');
        $this->assertSame(PullRequestState::Merged, PullRequest::sole()->state);

        Bus::assertNotDispatched(ReviewPullRequest::class);
    }

    public function test_events_we_do_not_use_are_marked_as_processed(): void
    {
        $this->githubWebhook('ping', ['zen' => 'Keep it simple.'])->assertStatus(202);

        $this->assertNotNull(WebhookDelivery::sole()->processed_at);
    }

    public function test_a_pull_request_of_an_unknown_installation_without_an_account_is_ignored(): void
    {
        // For example a late event after the owner deleted their Sprintlab account.
        $this->githubWebhook('pull_request', $this->pullRequestPayload())->assertStatus(202);

        $this->assertSame(0, GitHubInstallation::count());
        $this->assertSame(0, Repository::count());
        $this->assertSame(0, PullRequest::count());
        Bus::assertNotDispatched(ReviewPullRequest::class);
        $this->assertNotNull(WebhookDelivery::sole()->processed_at);
    }

    public function test_a_pull_request_of_a_known_installation_without_an_account_is_not_stored(): void
    {
        // The app was installed before its owner signed in to Sprintlab: without
        // a user there is no practice repository to compare with.
        $this->githubWebhook('installation', $this->installationPayload(senderId: 1), 'delivery-1');
        $this->githubWebhook('pull_request', $this->pullRequestPayload(), 'delivery-2');

        $this->assertSame(0, PullRequest::count());
    }

    public function test_changed_repositories_of_an_unknown_installation_without_an_account_are_ignored(): void
    {
        $this->githubWebhook('installation_repositories', [
            'action' => 'added',
            'installation' => ['id' => 9001, 'account' => ['login' => 'robin', 'id' => 4242]],
            'repositories_added' => [['id' => 7002, 'full_name' => 'robin/second', 'private' => false]],
            'repositories_removed' => [],
            'sender' => ['id' => 4242],
        ]);

        $this->assertSame(0, GitHubInstallation::count());
        $this->assertSame(0, Repository::count());
    }

    /**
     * Events other than `installation` only count when the installation is
     * known or its GitHub account has a Sprintlab user.
     */
    private function repositoryOwnerHasAnAccount(): User
    {
        $user = User::factory()->create(['github_id' => 4242]);

        // Pull requests only count in the practice repository, which is 7001 in the payloads.
        $installation = GitHubInstallation::create(['installation_id' => 9001, 'user_id' => $user->id, 'account_login' => 'robin', 'account_id' => 4242]);
        Repository::create(['github_id' => 7001, 'github_installation_id' => $installation->id, 'full_name' => 'robin/workkit', 'private' => true]);

        return $user;
    }
}
