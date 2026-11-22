<?php

namespace App\Jobs;

use App\Models\Ticket;
use App\Services\Review\UserReviewAssessmentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AssessUserReview implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    // The model may think for a while about a long review and diff.
    public int $timeout = 180;

    public function __construct(public int $ticketId, public int $githubReviewId) {}

    public function handle(UserReviewAssessmentService $assessments): void
    {
        $ticket = Ticket::find($this->ticketId);

        if ($ticket === null) {
            return;
        }

        $assessments->assess($ticket, $this->githubReviewId);
    }
}
