<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $installation_id
 * @property int|null $user_id
 * @property string $account_login
 * @property int $account_id
 * @property string|null $repository_selection
 * @property Carbon|null $suspended_at
 */
#[Fillable(['installation_id', 'user_id', 'account_login', 'account_id', 'repository_selection', 'suspended_at'])]
class GitHubInstallation extends Model
{
    protected $table = 'github_installations';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'suspended_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Repository, $this>
     */
    public function repositories(): HasMany
    {
        return $this->hasMany(Repository::class);
    }
}
