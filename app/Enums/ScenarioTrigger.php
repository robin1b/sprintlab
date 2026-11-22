<?php

namespace App\Enums;

/**
 * Something that happens in a sprint and can start a scenario event.
 */
enum ScenarioTrigger: string
{
    case SprintStarted = 'sprint_started';
    case DayStarted = 'day_started';
    case TicketStarted = 'ticket_started';
    case TicketInReview = 'ticket_in_review';
    case TicketDone = 'ticket_done';
    case SprintCompleted = 'sprint_completed';

    /**
     * Whether this trigger belongs to one ticket (so an event can be about that ticket only).
     */
    public function isTicketTrigger(): bool
    {
        return match ($this) {
            self::TicketStarted, self::TicketInReview, self::TicketDone => true,
            default => false,
        };
    }
}
