<?php

namespace Database\Factories;

use App\Enums\Colleague;
use App\Models\Message;
use App\Models\Sprint;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sprint_id' => Sprint::factory(),
            'colleague' => Colleague::Sarah,
            'from_user' => false,
            'body' => fake()->sentence(),
            'ticket_id' => null,
            'read_at' => null,
        ];
    }
}
