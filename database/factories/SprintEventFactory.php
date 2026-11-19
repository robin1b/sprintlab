<?php

namespace Database\Factories;

use App\Models\ScenarioEvent;
use App\Models\Sprint;
use App\Models\SprintEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<SprintEvent>
 */
class SprintEventFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sprint_id' => Sprint::factory(),
            'scenario_event_id' => ScenarioEvent::factory(),
            'due_at' => Carbon::now(),
            'fired_at' => null,
        ];
    }
}
