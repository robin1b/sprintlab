<?php

namespace App\Enums;

enum LessonStatus: string
{
    case Understood = 'understood';
    case NotUnderstood = 'not_understood';
}
