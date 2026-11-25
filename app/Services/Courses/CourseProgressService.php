<?php

namespace App\Services\Courses;

use App\Enums\LessonStatus;
use App\Models\CourseProgress;
use App\Models\User;

/**
 * What a signed-in user marked per lesson.
 */
class CourseProgressService
{
    public function mark(User $user, Course $course, string $lesson, LessonStatus $status): CourseProgress
    {
        return CourseProgress::query()->updateOrCreate(
            ['user_id' => $user->id, 'course' => $course->slug, 'lesson' => $lesson],
            ['status' => $status],
        );
    }

    /**
     * The status per lesson slug, for the lessons that were marked.
     *
     * @return array<string, string>
     */
    public function statuses(User $user, Course $course): array
    {
        return CourseProgress::query()
            ->where('user_id', $user->id)
            ->where('course', $course->slug)
            ->whereIn('lesson', $course->lessonSlugs())
            ->pluck('status', 'lesson')
            ->map(fn (LessonStatus $status): string => $status->value)
            ->all();
    }

    /**
     * How many lessons the user understood, per course slug.
     *
     * @param  list<Course>  $courses
     * @return array<string, int>
     */
    public function understoodCounts(User $user, array $courses): array
    {
        $understood = CourseProgress::query()
            ->where('user_id', $user->id)
            ->where('status', LessonStatus::Understood)
            ->get(['course', 'lesson']);

        $counts = [];

        foreach ($courses as $course) {
            $counts[$course->slug] = $understood
                ->where('course', $course->slug)
                ->whereIn('lesson', $course->lessonSlugs())
                ->count();
        }

        return $counts;
    }
}
