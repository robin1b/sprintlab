<?php

namespace App\Enums;

enum AssessmentStatus: string
{
    case Pending = 'pending';
    case Done = 'done';
    case Skipped = 'skipped';
    case Failed = 'failed';
}
