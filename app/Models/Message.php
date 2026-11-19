<?php

namespace App\Models;

use App\Enums\Colleague;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A chat message. `colleague` is the conversation partner, whoever wrote it:
 * a message from the user (`from_user`) belongs to the chat with that colleague.
 *
 * @property int $id
 * @property int $sprint_id
 * @property Colleague $colleague
 * @property bool $from_user
 * @property string $body
 * @property int|null $ticket_id
 * @property Carbon|null $read_at
 * @property-read Sprint $sprint
 * @property-read Ticket|null $ticket
 */
#[Fillable(['sprint_id', 'colleague', 'from_user', 'body', 'ticket_id', 'read_at'])]
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'colleague' => Colleague::class,
            'from_user' => 'boolean',
            'read_at' => 'datetime',
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
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
