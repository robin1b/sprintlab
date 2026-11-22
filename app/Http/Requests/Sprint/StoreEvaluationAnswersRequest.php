<?php

namespace App\Http\Requests\Sprint;

use App\Models\Sprint;
use App\Models\SprintEvaluation;
use Illuminate\Foundation\Http\FormRequest;

class StoreEvaluationAnswersRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Sprint $sprint */
        $sprint = $this->route('sprint');

        if (! $this->user()?->can('evaluate', $sprint)) {
            return false;
        }

        // Checked here, as validation runs before the controller.
        abort_unless($sprint->isCompleted(), 404);

        return true;
    }

    protected function prepareForValidation(): void
    {
        $answers = $this->input('answers');

        if (! is_array($answers)) {
            return;
        }

        // An answer of only spaces counts as empty.
        $this->merge([
            'answers' => array_map(fn ($answer) => is_string($answer) ? trim($answer) : $answer, $answers),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'answers' => ['required', 'array'],
            'answers.*' => ['required', 'string', 'min:1', 'max:'.(int) config('sprintlab.evaluation.answer_max_characters')],
        ];

        $questions = $this->evaluation()?->questions;

        // One answer per question; without questions the service refuses it.
        if ($questions !== null) {
            $rules['answers'][] = 'size:'.count($questions);
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'answers.*.required' => 'Please answer this question.',
            'answers.*.max' => 'Please keep this answer under :max characters.',
            'answers.size' => 'Please answer every question.',
        ];
    }

    private function evaluation(): ?SprintEvaluation
    {
        /** @var Sprint $sprint */
        $sprint = $this->route('sprint');

        return SprintEvaluation::query()->where('sprint_id', $sprint->id)->first();
    }
}
