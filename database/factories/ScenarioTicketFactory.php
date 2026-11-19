<?php

namespace Database\Factories;

use App\Enums\Colleague;
use App\Enums\TicketType;
use App\Models\Scenario;
use App\Models\ScenarioTicket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScenarioTicket>
 */
class ScenarioTicketFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scenario_id' => Scenario::factory(),
            'key' => 'WK-'.fake()->unique()->numberBetween(1, 999999),
            'day' => 1,
            'type' => TicketType::Bug,
            'reporter' => Colleague::Sarah,
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'acceptance_criteria' => [fake()->sentence()],
            'released_at_start' => true,
            'sort' => 0,
        ];
    }
}
