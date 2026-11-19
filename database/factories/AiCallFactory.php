<?php

namespace Database\Factories;

use App\Enums\AiFeature;
use App\Models\AiCall;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiCall>
 */
class AiCallFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $input = fake()->numberBetween(500, 20000);
        $output = fake()->numberBetween(50, 2000);

        return [
            'user_id' => null,
            'sprint_id' => null,
            'feature' => AiFeature::Chat,
            'model' => 'claude-haiku-5-5',
            'input_tokens' => $input,
            'output_tokens' => $output,
            'cost_micros' => intdiv($input * 100_000 + $output * 500_000, 1_000_000) + 1,
            'priced' => true,
        ];
    }

    public function feature(AiFeature $feature): static
    {
        return $this->state(['feature' => $feature]);
    }

    public function unpriced(): static
    {
        return $this->state(['model' => 'some-new-model', 'cost_micros' => 0, 'priced' => false]);
    }

    public function at(mixed $date): static
    {
        return $this->state(['created_at' => $date]);
    }
}
