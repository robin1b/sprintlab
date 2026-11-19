<?php

namespace App\Enums;

enum SprintStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';
}
