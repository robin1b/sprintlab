<?php

namespace App\Models;

use App\Enums\Colleague;
use App\Enums\TicketType;
use Database\Factories\ScenarioTicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $scenario_id
 * @property string $key
 * @property int $day
 * @property TicketType $type
 * @property Colleague $reporter
 * @property string $title
 * @property string $description
 * @property list<string> $acceptance_criteria
 * @property bool $released_at_start
 * @property int $sort
 * @property-read Scenario $scenario
 */
#[Fillable(['scenario_id', 'key', 'day', 'type', 'reporter', 'title', 'description', 'acceptance_criteria', 'released_at_start', 'sort'])]
class ScenarioTicket extends Model
{
    /** @use HasFactory<ScenarioTicketFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'day' => 'integer',
            'type' => TicketType::class,
            'reporter' => Colleague::class,
            'acceptance_criteria' => 'array',
            'released_at_start' => 'boolean',
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
