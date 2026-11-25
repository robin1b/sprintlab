<?php

namespace App\Models;

use App\Enums\LessonStatus;
use Database\Factories\CourseProgressFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Whether a user understood one lesson of a course.
 *
 * @property int $id
 * @property int $user_id
 * @property string $course
 * @property string $lesson
 * @property LessonStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'course', 'lesson', 'status'])]
class CourseProgress extends Model
{
    /** @use HasFactory<CourseProgressFactory> */
    use HasFactory;

    protected $table = 'course_progress';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => LessonStatus::class,
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
