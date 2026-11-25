<?php

use App\Http\Controllers\CourseController;
use App\Http\Controllers\CourseProgressController;
use App\Http\Controllers\CourseQuizController;
use Illuminate\Support\Facades\Route;

// The courses. Reading is public; keeping progress needs an account.
// `/courses` is not a folder that .htaccess blocks.
Route::get('courses', [CourseController::class, 'index'])->name('courses.index');
Route::get('courses/{course}', [CourseController::class, 'show'])->where('course', '[a-z0-9-]+')->name('courses.show');

// Saving is rate limited: nobody needs more than a click a second.
Route::middleware(['auth', 'throttle:60,1'])->group(function () {
    Route::post('courses/{course}/lessons/{lesson}/progress', [CourseProgressController::class, 'store'])
        ->where(['course' => '[a-z0-9-]+', 'lesson' => '[a-z0-9-]+'])
        ->name('courses.progress');
    Route::post('courses/{course}/quiz', [CourseQuizController::class, 'store'])
        ->where('course', '[a-z0-9-]+')
        ->name('courses.quiz');
});
