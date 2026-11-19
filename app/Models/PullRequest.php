<?php

namespace App\Models;

use App\Enums\PullRequestState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $repository_id
 * @property int $number
 * @property string $title
 * @property string|null $body
 * @property string $author_login
 * @property string $head_ref
 * @property string $head_sha
 * @property string $base_ref
 * @property PullRequestState $state
 * @property bool $draft
 * @property-read Repository $repository
 */
#[Fillable(['repository_id', 'number', 'title', 'body', 'author_login', 'head_ref', 'head_sha', 'base_ref', 'state', 'draft'])]
class PullRequest extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => PullRequestState::class,
            'draft' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /**
     * @return HasMany<Review, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }
}
