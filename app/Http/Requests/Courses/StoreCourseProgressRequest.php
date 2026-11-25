<?php

namespace App\Http\Requests\Courses;

use App\Enums\LessonStatus;
use App\Services\Courses\Course;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCourseProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Course $course */
        $course = $this->route('course');

        // A lesson that does not exist is a 404, before anything is validated.
        abort_if($course->lesson((string) $this->route('lesson')) === null, 404);

        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(LessonStatus::class)],
        ];
    }

    public function status(): LessonStatus
    {
        return LessonStatus::from($this->validated('status'));
    }
}
