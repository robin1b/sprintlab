<?php

namespace App\Policies;

use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    /**
     * A ticket belongs to the user who owns its sprint.
     */
    public function update(User $user, Ticket $ticket): bool
    {
        return $ticket->sprint()->value('user_id') === $user->id;
    }
}
