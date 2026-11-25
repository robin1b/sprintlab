<?php

namespace App\Http\Controllers;

use App\Services\Courses\Course;
use App\Services\Courses\CourseLibrary;
use App\Services\Courses\CourseProgressService;
use App\Services\Courses\CourseQuizService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CourseController extends Controller
{
    /**
     * The courses. Public: a guest can read them, a signed-in user also
     * sees how many lessons they understood.
     */
    public function index(Request $request, CourseLibrary $library, CourseProgressService $progress): Response
    {
        $courses = $library->all();
        $user = $request->user();
        $understood = $user !== null ? $progress->understoodCounts($user, $courses) : null;

        return Inertia::render('courses/Index', [
            'courses' => array_map(fn (Course $course): array => [
                'slug' => $course->slug,
                'title' => $course->title,
                'summary' => $course->summary,
                'minutes' => $course->minutes,
                'lessons' => count($course->lessons),
                'understood' => $understood[$course->slug] ?? null,
            ], $courses),
        ]);
    }

    /**
     * One course, whole, with the quiz answers: it is a learning quiz and
     * the feedback is instant.
     */
    public function show(Request $request, Course $course, CourseProgressService $progress, CourseQuizService $quiz): Response
    {
        $user = $request->user();
        $attempt = $user !== null ? $quiz->lastAttempt($user, $course) : null;

        return Inertia::render('courses/Show', [
            'course' => $course->toArray(),
            'progress' => $user !== null ? (object) $progress->statuses($user, $course) : null,
            'lastAttempt' => $attempt !== null ? [
                'score' => $attempt->score,
                'total' => $attempt->total,
                'at' => $attempt->created_at?->toIso8601String(),
            ] : null,
        ]);
    }
}
