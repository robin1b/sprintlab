<?php

namespace App\Models;

use App\Enums\SprintStatus;
use Database\Factories\SprintFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A user playing a scenario.
 *
 * @property int $id
 * @property int $user_id
 * @property int $scenario_id
 * @property SprintStatus $status
 * @property int $current_day
 * @property Carbon $started_at
 * @property Carbon $deadline_at
 * @property Carbon|null $paused_at
 * @property Carbon|null $completed_at
 * @property-read User $user
 * @property-read Scenario $scenario
 */
#[Fillable(['user_id', 'scenario_id', 'status', 'current_day', 'started_at', 'deadline_at', 'paused_at', 'completed_at'])]
class Sprint extends Model
{
    /** @use HasFactory<SprintFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SprintStatus::class,
            'current_day' => 'integer',
            'started_at' => 'datetime',
            'deadline_at' => 'datetime',
            'paused_at' => 'datetime',
            'completed_at' => 'datetime',
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
     * @return BelongsTo<Scenario, $this>
     */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class);
    }

    /**
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * @return HasMany<SprintEvent, $this>
     */
    public function sprintEvents(): HasMany
    {
        return $this->hasMany(SprintEvent::class);
    }

    public function isActive(): bool
    {
        return $this->status === SprintStatus::Active;
    }

    public function isPaused(): bool
    {
        return $this->status === SprintStatus::Paused;
    }

    public function isCompleted(): bool
    {
        return $this->status === SprintStatus::Completed;
    }

    /**
     * Only an active sprint can be late: a paused clock does not run.
     */
    public function isOverdue(): bool
    {
        return $this->isActive() && Carbon::now()->greaterThan($this->deadline_at);
    }
}
