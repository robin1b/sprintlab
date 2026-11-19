<?php

namespace App\Enums;

/**
 * What a scenario event does when it fires.
 */
enum ScenarioAction: string
{
    case Message = 'message';
    case ReleaseTicket = 'release_ticket';
    case UpdateTicket = 'update_ticket';
}
