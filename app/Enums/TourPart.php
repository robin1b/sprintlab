<?php

namespace App\Enums;

/**
 * The guided tour comes in parts, one per page, so each part starts on the
 * page it explains.
 */
enum TourPart: string
{
    case Overview = 'overview';
    case Board = 'board';
    case Chat = 'chat';
}
