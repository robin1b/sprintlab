<?php

namespace App\Models;

use App\Enums\Colleague;
use App\Enums\ReviewStatus;
use App\Enums\ReviewVerdict;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A review an AI colleague wrote for one commit of a pull request.
 *
 * @property int $id
 * @property int $pull_request_id
 * @property Colleague $colleague
 * @property string $head_sha
 * @property ReviewStatus $status
 * @property ReviewVerdict|null $verdict
 * @property string|null $summary
 * @property list<array{path: string, line: int, body: string}>|null $comments
 * @property int|null $github_review_id
 * @property string|null $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property string|null $error
 * @property-read PullRequest $pullRequest
 */
#[Fillable([
    'pull_request_id', 'colleague', 'head_sha', 'status', 'verdict', 'summary', 'comments',
    'github_review_id', 'model', 'input_tokens', 'output_tokens', 'error',
])]
class Review extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'colleague' => Colleague::class,
            'status' => ReviewStatus::class,
            'verdict' => ReviewVerdict::class,
            'comments' => 'array',
        ];
    }

    /**
     * @return BelongsTo<PullRequest, $this>
     */
    public function pullRequest(): BelongsTo
    {
        return $this->belongsTo(PullRequest::class);
    }
}
