<?php

namespace App\Services\Account;

use App\Jobs\RemoveGitHubInstallation;
use App\Models\GitHubInstallation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Removes a user and everything that belongs to them.
 *
 * Sprints, tickets, messages, events and evaluations go with the user through
 * foreign keys. The GitHub installation only points at the user with a
 * nullable key, so it is deleted explicitly (its repositories, pull requests,
 * reviews and assessments follow). The anonymous AI usage counters stay.
 */
class AccountDeletion
{
    public function delete(User $user): void
    {
        $installationIds = DB::transaction(function () use ($user): array {
            $ids = GitHubInstallation::query()
                ->where('user_id', $user->id)
                ->pluck('installation_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            GitHubInstallation::query()->where('user_id', $user->id)->delete();

            DB::table('sessions')->where('user_id', $user->id)->delete();

            $user->delete();

            return $ids;
        });

        // Only after the commit: tell GitHub that Sprintlab no longer has access.
        foreach ($installationIds as $installationId) {
            RemoveGitHubInstallation::dispatch($installationId);
        }
    }
}
