<?php

namespace App\Models;

use Database\Factories\SprintEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A scenario event scheduled for one sprint. The row is the schedule for
 * delayed events and the guarantee that an event fires once per sprint.
 *
 * @property int $id
 * @property int $sprint_id
 * @property int $scenario_event_id
 * @property Carbon $due_at
 * @property Carbon|null $fired_at
 * @property-read Sprint $sprint
 * @property-read ScenarioEvent $scenarioEvent
 */
#[Fillable(['sprint_id', 'scenario_event_id', 'due_at', 'fired_at'])]
class SprintEvent extends Model
{
    /** @use HasFactory<SprintEventFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'fired_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Sprint, $this>
     */
    public function sprint(): BelongsTo
    {
        return $this->belongsTo(Sprint::class);
    }

    /**
     * @return BelongsTo<ScenarioEvent, $this>
     */
    public function scenarioEvent(): BelongsTo
    {
        return $this->belongsTo(ScenarioEvent::class);
    }
}
