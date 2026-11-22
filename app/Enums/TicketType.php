<?php

namespace App\Enums;

enum TicketType: string
{
    case Bug = 'bug';
    case Feature = 'feature';
    case Review = 'review';
    case Hotfix = 'hotfix';
}
