<?php

namespace App\Services\GitHub;

use App\Enums\PullRequestState;
use App\Enums\ScenarioAction;
use App\Models\PullRequest;
use App\Models\ScenarioEvent;
use App\Models\Ticket;
use App\Services\Sprint\SprintService;
use RuntimeException;

/**
 * Opens the pull request of an AI colleague in the user's own repository,
 * through the GitHub App: a branch, the files of the scenario, the pull request.
 *
 * The files hold the planted problems, so they only travel from the scenario
 * to GitHub. They are never logged or sent to the browser.
 */
class ColleaguePullRequestService
{
    // A repository that was used for this many sprints already has other problems.
    private const MAX_BRANCH_ATTEMPTS = 8;

    public function __construct(
        private readonly GitHubApp $github,
        private readonly SprintService $sprints,
    ) {}

    /**
     * Safe to run twice: it reuses the open pull request or the unfinished branch it finds.
     *
     * @return PullRequest|null null when there is nothing to open, or no repository yet
     */
    public function open(Ticket $ticket, ScenarioEvent $event): ?PullRequest
    {
        if ($ticket->pullRequest !== null) {
            return $ticket->pullRequest;
        }

        if ($event->action !== ScenarioAction::OpenPullRequest || $event->colleague === null) {
            return null;
        }

        $repository = $this->sprints->repositoryFor($ticket->sprint);

        // No repository yet, or the app is suspended: `sprintlab:tick` tries again later.
        if ($repository === null || $repository->installation->suspended_at !== null) {
            return null;
        }

        $client = $this->github->installation($repository->installation->installation_id);
        $name = $repository->full_name;

        $payload = $event->payload;
        $branch = (string) $payload['branch'];
        $title = (string) $payload['title'];
        $base = $client->repository($name)['default_branch'];

        $text = "**{$event->colleague->displayName()}** ({$event->colleague->role()})\n\n".(string) $payload['description'];

        [$branch, $opened, $branchExists] = $this->findBranch($client, $name, $branch);

        if ($opened === null) {
            if (! $branchExists) {
                $client->createBranch($name, $branch, $this->startingPoint($client, $name, $base));
            }

            $message = is_string($payload['commit_message'] ?? null) && $payload['commit_message'] !== ''
                ? $payload['commit_message']
                : $title;

            foreach ((array) $payload['files'] as $file) {
                $path = (string) $file['path'];

                $client->putFile($name, $path, (string) $file['content'], $message, $branch, $client->fileSha($name, $path, $branch));
            }

            $opened = $client->createPullRequest($name, $title, $text, $branch, $base);
        }

        $pullRequest = PullRequest::updateOrCreate(
            ['repository_id' => $repository->id, 'number' => $opened['number']],
            [
                'title' => $title,
                'body' => $text,
                'author_login' => $opened['author_login'],
                'head_ref' => $branch,
                'head_sha' => $opened['head_sha'],
                'base_ref' => $base,
                'state' => PullRequestState::Open,
                'draft' => false,
            ],
        );

        // The status stays: the user reviews this pull request, and only a good review moves the ticket.
        $ticket->update(['pull_request_id' => $pullRequest->id]);

        return $pullRequest;
    }

    /**
     * Which branch to use, and what is already there.
     *
     * An open pull request is reused, and so is a branch that never had one
     * (an earlier attempt that stopped halfway). A branch whose pull request
     * was closed is left over from an earlier sprint in the same repository:
     * it is never touched, the colleague takes the next free name.
     *
     * @return array{0: string, 1: array{number: int, head_sha: string, author_login: string}|null, 2: bool}
     */
    private function findBranch(InstallationClient $client, string $repository, string $branch): array
    {
        $candidate = $branch;

        for ($attempt = 2; $attempt <= self::MAX_BRANCH_ATTEMPTS + 1; $attempt++) {
            $opened = $client->openPullRequest($repository, $candidate);

            if ($opened !== null) {
                return [$candidate, $opened, true];
            }

            if ($client->branchSha($repository, $candidate) === null) {
                return [$candidate, null, false];
            }

            if (! $client->hasPullRequest($repository, $candidate)) {
                return [$candidate, null, true];
            }

            $candidate = "{$branch}-{$attempt}";
        }

        throw new RuntimeException("No free branch name for {$branch} in {$repository}.");
    }

    /**
     * The commit the colleague's branch starts from: the first commit of the
     * repository, which is the template the scenario's files were written
     * against. The pull request then shows the colleague's changes and
     * nothing else, whatever the user changed in those files since.
     */
    private function startingPoint(InstallationClient $client, string $repository, string $base): string
    {
        return $client->rootCommitSha($repository, $base)
            ?? $client->branchSha($repository, $base)
            ?? throw new RuntimeException("Branch {$base} of {$repository} was not found.");
    }
}
