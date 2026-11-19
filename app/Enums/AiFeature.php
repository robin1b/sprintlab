<?php

namespace App\Enums;

enum AiFeature: string
{
    case Review = 'review';
    case Chat = 'chat';
    case Assessment = 'assessment';

    public function label(): string
    {
        return match ($this) {
            self::Review => 'Pull request review',
            self::Chat => 'Chat reply',
            self::Assessment => 'Review assessment',
        };
    }
}
