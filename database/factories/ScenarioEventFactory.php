<?php

namespace Database\Factories;

use App\Enums\Colleague;
use App\Enums\ScenarioAction;
use App\Enums\ScenarioTrigger;
use App\Models\Scenario;
use App\Models\ScenarioEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScenarioEvent>
 */
class ScenarioEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scenario_id' => Scenario::factory(),
            'key' => fake()->unique()->slug(2),
            'trigger' => ScenarioTrigger::SprintStarted,
            'ticket_key' => null,
            'day' => null,
            'delay_minutes' => 0,
            'action' => ScenarioAction::Message,
            'colleague' => Colleague::Sarah,
            'payload' => ['body' => fake()->sentence()],
            'sort' => 0,
        ];
    }
}
