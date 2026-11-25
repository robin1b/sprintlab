<?php

namespace App\Http\Controllers;

use App\Http\Requests\Courses\StoreCourseQuizRequest;
use App\Services\Courses\Course;
use App\Services\Courses\CourseQuizService;
use Illuminate\Http\RedirectResponse;

class CourseQuizController extends Controller
{
    public function store(StoreCourseQuizRequest $request, Course $course, CourseQuizService $quiz): RedirectResponse
    {
        $quiz->submit($request->user(), $course, $request->answers());

        return back();
    }
}
