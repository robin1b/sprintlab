<?php

namespace App\Enums;

enum ReviewVerdict: string
{
    case Approve = 'approve';
    case RequestChanges = 'request_changes';
    case Comment = 'comment';

    /**
     * The name GitHub uses for this verdict when a review is submitted.
     *
     * @return 'APPROVE'|'REQUEST_CHANGES'|'COMMENT'
     */
    public function githubEvent(): string
    {
        return match ($this) {
            self::Approve => 'APPROVE',
            self::RequestChanges => 'REQUEST_CHANGES',
            self::Comment => 'COMMENT',
        };
    }
}
