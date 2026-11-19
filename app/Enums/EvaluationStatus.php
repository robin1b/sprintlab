<?php

namespace App\Enums;

enum EvaluationStatus: string
{
    case Preparing = 'preparing';
    case AwaitingAnswers = 'awaiting_answers';
    case Writing = 'writing';
    case Completed = 'completed';
    case Failed = 'failed';
}
