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
            'planted_issues' => null,
            'sort' => 0,
        ];
    }

    /**
     * A review ticket whose pull request hides the given problems.
     *
     * @param  list<array{id: string, file?: string, description: string, severity: string}>  $issues
     */
    public function withPlantedIssues(array $issues): static
    {
        return $this->state(fn () => ['type' => TicketType::Review, 'planted_issues' => $issues]);
    }
}
