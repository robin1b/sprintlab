<?php

namespace App\Policies;

use App\Models\Sprint;
use App\Models\User;

class SprintPolicy
{
    public function pause(User $user, Sprint $sprint): bool
    {
        return $sprint->user_id === $user->id;
    }

    public function resume(User $user, Sprint $sprint): bool
    {
        return $sprint->user_id === $user->id;
    }
}
