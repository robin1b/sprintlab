<?php

namespace App\Jobs;

use App\Services\GitHub\GitHubApp;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Removes the Sprintlab GitHub App from a user's account on GitHub's side,
 * after that user deleted their Sprintlab account.
 */
class RemoveGitHubInstallation implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $installationId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(GitHubApp $app): void
    {
        $app->deleteInstallation($this->installationId);
    }
}
