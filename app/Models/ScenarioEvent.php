<?php

namespace App\Models;

use App\Enums\Colleague;
use App\Enums\ScenarioAction;
use App\Enums\ScenarioTrigger;
use Database\Factories\ScenarioEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One scripted thing that happens when a trigger occurs in a sprint.
 *
 * `payload` holds the action's own data: `body`, `add_criteria`,
 * `description_append` and `title`.
 *
 * @property int $id
 * @property int $scenario_id
 * @property string $key
 * @property ScenarioTrigger $trigger
 * @property string|null $ticket_key
 * @property int|null $day
 * @property int $delay_minutes
 * @property ScenarioAction $action
 * @property Colleague|null $colleague
 * @property array<string, mixed> $payload
 * @property int $sort
 * @property-read Scenario $scenario
 */
#[Fillable(['scenario_id', 'key', 'trigger', 'ticket_key', 'day', 'delay_minutes', 'action', 'colleague', 'payload', 'sort'])]
class ScenarioEvent extends Model
{
    /** @use HasFactory<ScenarioEventFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger' => ScenarioTrigger::class,
            'day' => 'integer',
            'delay_minutes' => 'integer',
            'action' => ScenarioAction::class,
            'colleague' => Colleague::class,
            'payload' => 'array',
            'sort' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Scenario, $this>
     */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class);
    }
}
