<?php

namespace Database\Factories;

use App\Enums\SprintStatus;
use App\Models\Scenario;
use App\Models\Sprint;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Sprint>
 */
class SprintFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'scenario_id' => Scenario::factory(),
            'repository_id' => null,
            'status' => SprintStatus::Active,
            'current_day' => 1,
            'started_at' => Carbon::now(),
            'deadline_at' => Carbon::now()->addDays(7),
            'paused_at' => null,
            'completed_at' => null,
        ];
    }

    public function paused(): static
    {
        return $this->state(fn () => [
            'status' => SprintStatus::Paused,
            'paused_at' => Carbon::now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => SprintStatus::Completed,
            'completed_at' => Carbon::now(),
        ]);
    }
}
