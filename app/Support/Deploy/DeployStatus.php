<?php

namespace App\Support\Deploy;

use App\Console\Commands\DeploySite;

/**
 * The last result of the two deployment steps, for the admin page: the
 * packages (scripts/cron.php) and the release (`site:deploy`).
 */
class DeployStatus
{
    /**
     * @return list<array{key: string, label: string, ok: bool|null, at: string|null, message: string|null}>
     */
    public static function steps(): array
    {
        return [
            self::step('packages', 'Packages', PackageInstaller::state(base_path())),
            self::step('release', 'Release', DeploySite::state()),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{key: string, label: string, ok: bool|null, at: string|null, message: string|null}
     */
    private static function step(string $key, string $label, array $state): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'ok' => is_bool($state['ok'] ?? null) ? $state['ok'] : null,
            'at' => is_string($state['at'] ?? null) ? $state['at'] : null,
            'message' => is_string($state['message'] ?? null) ? $state['message'] : null,
        ];
    }
}
