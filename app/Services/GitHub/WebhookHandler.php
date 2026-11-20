<?php

namespace App\Services\GitHub;

use App\Enums\PullRequestState;
use App\Enums\TicketType;
use App\Jobs\AssessUserReview;
use App\Jobs\ReviewPullRequest;
use App\Models\GitHubInstallation;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Services\Sprint\PullRequestTicketLinker;
use Illuminate\Support\Carbon;

/**
 * Turns a stored webhook call from GitHub into changes in our database.
 *
 * GitHub tells us three things we care about: the app was installed or
 * removed, repositories were added or removed, and something happened to a
 * pull request.
 */
class WebhookHandler
{
    // Only these pull request actions mean "there is new code to look at".
    private const REVIEWABLE_ACTIONS = ['opened', 'reopened', 'synchronize', 'ready_for_review'];

    public function __construct(private readonly PullRequestTicketLinker $linker) {}

    public function handle(WebhookDelivery $delivery): void
    {
        $payload = $delivery->payload;

        match ($delivery->event) {
            'installation' => $this->installation((string) $delivery->action, $payload),
            'installation_repositories' => $this->installationRepositories($payload),
            'pull_request' => $this->pullRequest((string) $delivery->action, $payload),
            'pull_request_review' => $this->pullRequestReview((string) $delivery->action, $payload),
            'create' => $this->branchCreated($payload),
            default => null, // ping and every event we did not ask for
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function installation(string $action, array $payload): void
    {
        $installationId = (int) data_get($payload, 'installation.id');

        if ($action === 'deleted') {
            GitHubInstallation::where('installation_id', $installationId)->delete();

            return;
        }

        $installation = $this->storeInstallation(
            $installationId,
            (string) data_get($payload, 'installation.account.login'),
            (int) data_get($payload, 'installation.account.id'),
            // The person who clicked "Install" is the Sprintlab user.
            (int) data_get($payload, 'sender.id'),
        );

        if ($action === 'suspend') {
            $installation->update(['suspended_at' => Carbon::now()]);
        } elseif ($action === 'unsuspend') {
            $installation->update(['suspended_at' => null]);
        }

        foreach ((array) data_get($payload, 'repositories', []) as $repository) {
            $this->storeRepository($installation, (array) $repository);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function installationRepositories(array $payload): void
    {
        if (! $this->mayStore((int) data_get($payload, 'installation.id'), (int) data_get($payload, 'sender.id'))) {
            return;
        }

        $installation = $this->storeInstallation(
            (int) data_get($payload, 'installation.id'),
            (string) data_get($payload, 'installation.account.login'),
            (int) data_get($payload, 'installation.account.id'),
            (int) data_get($payload, 'sender.id'),
        );

        foreach ((array) data_get($payload, 'repositories_added', []) as $repository) {
            $this->storeRepository($installation, (array) $repository);
        }

        $removed = array_map(
            fn (mixed $repository): int => (int) data_get($repository, 'id'),
            (array) data_get($payload, 'repositories_removed', []),
        );

        if ($removed !== []) {
            Repository::whereIn('github_id', $removed)->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function pullRequest(string $action, array $payload): void
    {
        if (! $this->mayStore((int) data_get($payload, 'installation.id'), (int) data_get($payload, 'repository.owner.id'))) {
            return;
        }

        // A pull request event only carries the installation id, so the
        // account comes from the repository owner.
        $installation = $this->storeInstallation(
            (int) data_get($payload, 'installation.id'),
            (string) data_get($payload, 'repository.owner.login'),
            (int) data_get($payload, 'repository.owner.id'),
            (int) data_get($payload, 'repository.owner.id'),
        );

        $repository = $this->storeRepository($installation, (array) data_get($payload, 'repository', []));

        $state = match (true) {
            (bool) data_get($payload, 'pull_request.merged') => PullRequestState::Merged,
            data_get($payload, 'pull_request.state') === 'closed' => PullRequestState::Closed,
            default => PullRequestState::Open,
        };

        $pullRequest = PullRequest::updateOrCreate(
            ['repository_id' => $repository->id, 'number' => (int) data_get($payload, 'pull_request.number')],
            [
                'title' => (string) data_get($payload, 'pull_request.title'),
                'body' => data_get($payload, 'pull_request.body'),
                'author_login' => (string) data_get($payload, 'pull_request.user.login'),
                'head_ref' => (string) data_get($payload, 'pull_request.head.ref'),
                'head_sha' => (string) data_get($payload, 'pull_request.head.sha'),
                'base_ref' => (string) data_get($payload, 'pull_request.base.ref'),
                'state' => $state,
                'draft' => (bool) data_get($payload, 'pull_request.draft'),
            ],
        );

        $this->linker->link($pullRequest, $action);

        $writtenByBot = data_get($payload, 'pull_request.user.type') === 'Bot';

        if (
            in_array($action, self::REVIEWABLE_ACTIONS, true)
            && $pullRequest->state === PullRequestState::Open
            && ! $pullRequest->draft
            && ! $writtenByBot
            && $installation->suspended_at === null
        ) {
            ReviewPullRequest::dispatch($pullRequest->id, $pullRequest->head_sha);
        }
    }

    /**
     * The user submitted a review of the colleague's pull request: have it judged.
     *
     * @param  array<string, mixed>  $payload
     */
    private function pullRequestReview(string $action, array $payload): void
    {
        $reviewId = (int) data_get($payload, 'review.id');

        // A review that is only a draft (pending) is not submitted yet.
        if ($action !== 'submitted' || $reviewId === 0 || data_get($payload, 'review.user.type') === 'Bot') {
            return;
        }

        $githubId = (int) data_get($payload, 'sender.id');

        $pullRequest = PullRequest::query()
            ->where('number', (int) data_get($payload, 'pull_request.number'))
            ->whereHas('repository', fn ($query) => $query->where('github_id', (int) data_get($payload, 'repository.id')))
            ->first();

        if ($githubId === 0 || $pullRequest === null) {
            return;
        }

        // Only the user who owns the sprint can be graded, so someone else's
        // review on the same pull request is ignored.
        $ticket = Ticket::query()
            ->where('pull_request_id', $pullRequest->id)
            ->where('type', TicketType::Review)
            ->whereHas('sprint.user', fn ($query) => $query->where('github_id', $githubId))
            ->first();

        if ($ticket !== null) {
            AssessUserReview::dispatch($ticket->id, $reviewId);
        }
    }

    /**
     * A new branch was pushed: the ticket named in it is now being worked on.
     * Branches made by the app itself (a colleague's pull request) do not count.
     *
     * @param  array<string, mixed>  $payload
     */
    private function branchCreated(array $payload): void
    {
        if (data_get($payload, 'ref_type') !== 'branch' || data_get($payload, 'sender.type') === 'Bot') {
            return;
        }

        $installation = GitHubInstallation::where('installation_id', (int) data_get($payload, 'installation.id'))->first();
        $user = $installation?->user;

        if ($user !== null) {
            $this->linker->branchCreated($user, (string) data_get($payload, 'ref'));
        }
    }

    /**
     * An `installation` event is someone installing the app, so it is always
     * stored. Any other event only counts when we know its installation, or
     * when its GitHub account has a Sprintlab user: after an account was
     * deleted, a late event must not bring that person's data back.
     */
    private function mayStore(int $installationId, int $githubUserId): bool
    {
        return GitHubInstallation::where('installation_id', $installationId)->exists()
            || User::where('github_id', $githubUserId)->exists();
    }

    private function storeInstallation(int $installationId, string $accountLogin, int $accountId, int $githubUserId): GitHubInstallation
    {
        $installation = GitHubInstallation::firstOrNew(['installation_id' => $installationId]);

        $installation->account_login = $accountLogin;
        $installation->account_id = $accountId;

        // Link the installation to the Sprintlab user with that GitHub account.
        if ($installation->user_id === null) {
            $installation->user_id = User::where('github_id', $githubUserId)->value('id');
        }

        $installation->save();

        return $installation;
    }

    /**
     * @param  array<array-key, mixed>  $repository
     */
    private function storeRepository(GitHubInstallation $installation, array $repository): Repository
    {
        return Repository::updateOrCreate(
            ['github_id' => (int) data_get($repository, 'id')],
            [
                'github_installation_id' => $installation->id,
                'full_name' => (string) data_get($repository, 'full_name'),
                'private' => (bool) data_get($repository, 'private'),
            ],
        );
    }
}
