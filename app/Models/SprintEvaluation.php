<?php

namespace App\Models;

use App\Enums\EvaluationStatus;
use App\Enums\Level;
use Database\Factories\SprintEvaluationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The team lead's evaluation of a completed sprint.
 *
 * @property int $id
 * @property int $sprint_id
 * @property EvaluationStatus $status
 * @property Level $level
 * @property array<string, int|null>|null $scores
 * @property int|null $overall
 * @property bool|null $passed
 * @property string|null $communication_evidence
 * @property list<string>|null $questions
 * @property list<string>|null $answers
 * @property list<array{understanding: int, note: string}>|null $answer_notes
 * @property string|null $strengths
 * @property string|null $growth
 * @property string|null $advice
 * @property string|null $promoted_to
 * @property string|null $error
 * @property Carbon|null $completed_at
 * @property-read Sprint $sprint
 */
#[Fillable([
    'sprint_id', 'status', 'level', 'scores', 'overall', 'passed', 'communication_evidence', 'questions',
    'answers', 'answer_notes', 'strengths', 'growth', 'advice', 'promoted_to', 'error', 'completed_at',
])]
class SprintEvaluation extends Model
{
    /** @use HasFactory<SprintEvaluationFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EvaluationStatus::class,
            'level' => Level::class,
            'scores' => 'array',
            'overall' => 'integer',
            'passed' => 'boolean',
            'questions' => 'array',
            'answers' => 'array',
            'answer_notes' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Sprint, $this>
     */
    public function sprint(): BelongsTo
    {
        return $this->belongsTo(Sprint::class);
    }
}
