<?php

namespace App\Models;

use App\Enums\Level;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $github_id
 * @property string $github_username
 * @property string $name
 * @property string|null $email
 * @property string|null $avatar_url
 * @property Level $level
 * @property int|null $practice_repository_id
 * @property list<string>|null $tours_done
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['github_id', 'github_username', 'name', 'email', 'avatar_url', 'practice_repository_id', 'tours_done'])]
#[Hidden(['remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'github_id' => 'integer',
            'practice_repository_id' => 'integer',
            'level' => Level::class,
            'tours_done' => 'array',
        ];
    }

    /**
     * @return HasMany<Sprint, $this>
     */
    public function sprints(): HasMany
    {
        return $this->hasMany(Sprint::class);
    }

    /**
     * @return HasMany<CourseProgress, $this>
     */
    public function courseProgress(): HasMany
    {
        return $this->hasMany(CourseProgress::class);
    }

    /**
     * @return HasMany<CourseQuizAttempt, $this>
     */
    public function courseQuizAttempts(): HasMany
    {
        return $this->hasMany(CourseQuizAttempt::class);
    }

    /**
     * The repository the user chose to work in, when they have several.
     *
     * @return BelongsTo<Repository, $this>
     */
    public function practiceRepository(): BelongsTo
    {
        return $this->belongsTo(Repository::class, 'practice_repository_id');
    }
}
