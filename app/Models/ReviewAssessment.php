<?php

namespace App\Models;

use App\Enums\AssessmentStatus;
use App\Enums\ReviewVerdict;
use Database\Factories\ReviewAssessmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How well the user's review of a colleague's pull request found the
 * planted problems.
 *
 * @property int $id
 * @property int $ticket_id
 * @property int $pull_request_id
 * @property int $github_review_id
 * @property string $reviewer_login
 * @property AssessmentStatus $status
 * @property ReviewVerdict|null $verdict
 * @property list<array{id: string, found: bool, evidence: string}>|null $issues
 * @property list<string>|null $false_positives
 * @property int|null $clarity
 * @property int|null $tone
 * @property int|null $score
 * @property string|null $feedback
 * @property string|null $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string|null $error
 * @property-read Ticket $ticket
 * @property-read PullRequest $pullRequest
 */
#[Fillable([
    'ticket_id', 'pull_request_id', 'github_review_id', 'reviewer_login', 'status', 'verdict', 'issues',
    'false_positives', 'clarity', 'tone', 'score', 'feedback', 'model', 'input_tokens', 'output_tokens', 'error',
])]
class ReviewAssessment extends Model
{
    /** @use HasFactory<ReviewAssessmentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AssessmentStatus::class,
            'verdict' => ReviewVerdict::class,
            'issues' => 'array',
            'false_positives' => 'array',
            'clarity' => 'integer',
            'tone' => 'integer',
            'score' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<PullRequest, $this>
     */
    public function pullRequest(): BelongsTo
    {
        return $this->belongsTo(PullRequest::class);
    }
}
