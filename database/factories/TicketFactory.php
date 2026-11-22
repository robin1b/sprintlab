<?php

namespace Database\Factories;

use App\Enums\Colleague;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\ScenarioTicket;
use App\Models\Sprint;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sprint_id' => Sprint::factory(),
            'scenario_ticket_id' => ScenarioTicket::factory(),
            'key' => 'WK-'.fake()->unique()->numberBetween(1, 999999),
            'day' => 1,
            'type' => TicketType::Bug,
            'reporter' => Colleague::Sarah,
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'acceptance_criteria' => [fake()->sentence()],
            'status' => TicketStatus::Todo,
            'released_at' => null,
            'pull_request_id' => null,
            'sort' => 0,
        ];
    }

    public function released(): static
    {
        return $this->state(fn () => ['released_at' => Carbon::now()]);
    }

    public function status(TicketStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
