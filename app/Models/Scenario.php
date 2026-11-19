<?php

namespace App\Models;

use Database\Factories\ScenarioFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The script of a sprint: tickets and events, imported from a JSON file.
 *
 * @property int $id
 * @property string $slug
 * @property string $title
 * @property string $level
 * @property string|null $summary
 * @property int $duration_days
 */
#[Fillable(['slug', 'title', 'level', 'summary', 'duration_days'])]
class Scenario extends Model
{
    /** @use HasFactory<ScenarioFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_days' => 'integer',
        ];
    }

    /**
     * @return HasMany<ScenarioTicket, $this>
     */
    public function scenarioTickets(): HasMany
    {
        return $this->hasMany(ScenarioTicket::class);
    }

    /**
     * @return HasMany<ScenarioEvent, $this>
     */
    public function scenarioEvents(): HasMany
    {
        return $this->hasMany(ScenarioEvent::class);
    }
}
