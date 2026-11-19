<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $github_id
 * @property int $github_installation_id
 * @property string $full_name
 * @property bool $private
 * @property-read GitHubInstallation $installation
 */
#[Fillable(['github_id', 'github_installation_id', 'full_name', 'private'])]
class Repository extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'private' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<GitHubInstallation, $this>
     */
    public function installation(): BelongsTo
    {
        return $this->belongsTo(GitHubInstallation::class, 'github_installation_id');
    }

    /**
     * @return HasMany<PullRequest, $this>
     */
    public function pullRequests(): HasMany
    {
        return $this->hasMany(PullRequest::class);
    }
}
