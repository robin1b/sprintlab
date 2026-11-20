<?php

namespace App\Http\Requests\Sprint;

use App\Models\Scenario;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StartSprintRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'scenario_id' => [
                'required',
                'integer',
                'exists:scenarios,id',
                // Your own level, or a scenario outside the tracks (a demo or an extra).
                function (string $attribute, mixed $value, Closure $fail): void {
                    $scenario = Scenario::query()->whereKey($value)->first();

                    if ($scenario !== null && $scenario->sequence !== null && $scenario->level !== $this->user()?->level->value) {
                        $fail('This sprint is not available at your level.');
                    }
                },
            ],
        ];
    }
}
