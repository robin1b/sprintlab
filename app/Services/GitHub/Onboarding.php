<?php

namespace App\Services\GitHub;

use App\Models\GitHubInstallation;
use App\Models\Repository;
use App\Models\User;
use App\Services\Sprint\SprintService;

/**
 * Where a user stands on the way to a first sprint: template, app, repository.
 */
class Onboarding
{
    public function __construct(private readonly SprintService $sprints) {}

    /**
     * @return array{
     *     template_repository: string,
     *     template_url: string,
     *     install_url: string|null,
     *     installed: bool,
     *     suspended: bool,
     *     repositories: list<array{id: int, full_name: string, private: bool, url: string}>,
     *     repository: array{id: int, full_name: string, private: bool, url: string}|null,
     *     ready: bool
     * }
     */
    public function stateFor(User $user): array
    {
        $template = (string) config('sprintlab.template_repository');
        $slug = (string) config('github.app_slug');

        $installations = GitHubInstallation::query()->where('user_id', $user->id)->get();
        $active = $installations->whereNull('suspended_at');

        $repositories = array_values(Repository::query()
            ->whereIn('github_installation_id', $active->pluck('id'))
            ->orderBy('full_name')
            ->get()
            ->map(fn (Repository $repository): array => $this->present($repository))
            ->all());

        $repository = $this->sprints->connectedRepositoryOf($user);

        return [
            'template_repository' => $template,
            'template_url' => "https://github.com/{$template}/generate",
            'install_url' => $slug === '' ? null : "https://github.com/apps/{$slug}/installations/new",
            'installed' => $active->isNotEmpty(),
            'suspended' => $installations->isNotEmpty() && $active->isEmpty(),
            'repositories' => $repositories,
            'repository' => $repository === null ? null : $this->present($repository),
            'ready' => $repository !== null,
        ];
    }

    /**
     * An installation made before the user signed up belongs to the user with
     * that GitHub account.
     */
    public function linkInstallationsOf(User $user): int
    {
        return GitHubInstallation::query()
            ->whereNull('user_id')
            ->where('account_id', $user->github_id)
            ->update(['user_id' => $user->id]);
    }

    /**
     * @return array{id: int, full_name: string, private: bool, url: string}
     */
    private function present(Repository $repository): array
    {
        return [
            'id' => $repository->id,
            'full_name' => $repository->full_name,
            'private' => $repository->private,
            'url' => "https://github.com/{$repository->full_name}",
        ];
    }
}
