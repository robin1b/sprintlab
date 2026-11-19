<?php

namespace App\Models;

use App\Enums\Colleague;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The user's own copy of a scenario ticket. Events can change it mid-sprint.
 *
 * @property int $id
 * @property int $sprint_id
 * @property int $scenario_ticket_id
 * @property string $key
 * @property int $day
 * @property TicketType $type
 * @property Colleague $reporter
 * @property string $title
 * @property string $description
 * @property list<string> $acceptance_criteria
 * @property TicketStatus $status
 * @property Carbon|null $released_at
 * @property int|null $pull_request_id
 * @property int $sort
 * @property-read Sprint $sprint
 * @property-read ScenarioTicket $scenarioTicket
 * @property-read PullRequest|null $pullRequest
 */
#[Fillable([
    'sprint_id', 'scenario_ticket_id', 'key', 'day', 'type', 'reporter', 'title', 'description',
    'acceptance_criteria', 'status', 'released_at', 'pull_request_id', 'sort',
])]
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
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
            'status' => TicketStatus::class,
            'released_at' => 'datetime',
            'sort' => 'integer',
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
     * @return BelongsTo<ScenarioTicket, $this>
     */
    public function scenarioTicket(): BelongsTo
    {
        return $this->belongsTo(ScenarioTicket::class);
    }

    /**
     * @return BelongsTo<PullRequest, $this>
     */
    public function pullRequest(): BelongsTo
    {
        return $this->belongsTo(PullRequest::class);
    }

    public function isReleased(): bool
    {
        return $this->released_at !== null;
    }
}
