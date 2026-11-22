<?php

namespace App\Http\Requests\Settings;

use App\Models\Repository;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePracticeRepositoryRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'repository_id' => [
                'required',
                'integer',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($this->findRepository((int) $value) === null) {
                        $fail('Choose one of your connected repositories.');
                    }
                },
            ],
        ];
    }

    public function repository(): Repository
    {
        return $this->findRepository((int) $this->validated('repository_id')) ?? abort(422);
    }

    private function findRepository(int $id): ?Repository
    {
        return Repository::query()
            ->whereKey($id)
            ->whereHas('installation', function ($installations): void {
                $installations->where('user_id', $this->user()?->id)->whereNull('suspended_at');
            })
            ->first();
    }
}
