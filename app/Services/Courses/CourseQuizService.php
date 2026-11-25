<?php

namespace App\Services\Courses;

use App\Models\CourseQuizAttempt;
use App\Models\User;

/**
 * Scoring the quiz of a course. The score is always worked out here, from
 * the answers and the course file: a score from the browser is never used.
 */
class CourseQuizService
{
    /**
     * @param  list<int>  $answers  one answer per question, in order
     */
    public function submit(User $user, Course $course, array $answers): CourseQuizAttempt
    {
        $score = 0;

        foreach ($course->quiz as $index => $question) {
            if (($answers[$index] ?? null) === $question['answer']) {
                $score++;
            }
        }

        return CourseQuizAttempt::query()->create([
            'user_id' => $user->id,
            'course' => $course->slug,
            'score' => $score,
            'total' => count($course->quiz),
            'answers' => $answers,
        ]);
    }

    public function lastAttempt(User $user, Course $course): ?CourseQuizAttempt
    {
        return CourseQuizAttempt::query()
            ->where('user_id', $user->id)
            ->where('course', $course->slug)
            ->latest('id')
            ->first();
    }
}
