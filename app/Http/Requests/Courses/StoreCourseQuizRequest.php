<?php

namespace App\Http\Requests\Courses;

use App\Services\Courses\Course;
use Illuminate\Foundation\Http\FormRequest;

class StoreCourseQuizRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * One whole number per question, inside the number of options of that question.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var Course $course */
        $course = $this->route('course');

        $rules = ['answers' => ['required', 'array', 'size:'.count($course->quiz)]];

        foreach ($course->quiz as $index => $question) {
            $rules["answers.{$index}"] = ['required', 'integer', 'between:0,'.(count($question['options']) - 1)];
        }

        return $rules;
    }

    /**
     * @return list<int>
     */
    public function answers(): array
    {
        /** @var array<int|string, int|string> $answers */
        $answers = $this->validated('answers');

        return array_values(array_map('intval', $answers));
    }
}
