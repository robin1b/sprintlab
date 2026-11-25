<?php

namespace App\Http\Controllers;

use App\Http\Requests\Courses\StoreCourseProgressRequest;
use App\Services\Courses\Course;
use App\Services\Courses\CourseProgressService;
use Illuminate\Http\RedirectResponse;

class CourseProgressController extends Controller
{
    public function store(StoreCourseProgressRequest $request, Course $course, string $lesson, CourseProgressService $progress): RedirectResponse
    {
        $progress->mark($request->user(), $course, $lesson, $request->status());

        return back();
    }
}
