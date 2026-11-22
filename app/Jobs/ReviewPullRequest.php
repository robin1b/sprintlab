<?php

namespace App\Jobs;

use App\Models\PullRequest;
use App\Services\Review\PullRequestReviewService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ReviewPullRequest implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    // The model may think for a while about a large diff.
    public int $timeout = 180;

    public function __construct(public int $pullRequestId, public string $headSha) {}

    public function handle(PullRequestReviewService $reviews): void
    {
        $pullRequest = PullRequest::find($this->pullRequestId);

        if ($pullRequest === null) {
            return;
        }

        $reviews->review($pullRequest, $this->headSha);
    }
}
