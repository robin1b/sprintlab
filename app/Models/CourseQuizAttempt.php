<?php

namespace App\Models;

use Database\Factories\CourseQuizAttemptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One time a user finished the quiz of a course.
 *
 * @property int $id
 * @property int $user_id
 * @property string $course
 * @property int $score
 * @property int $total
 * @property list<int> $answers
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'course', 'score', 'total', 'answers'])]
class CourseQuizAttempt extends Model
{
    /** @use HasFactory<CourseQuizAttemptFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'total' => 'integer',
            'answers' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
