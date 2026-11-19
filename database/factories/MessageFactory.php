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
            'answered_at' => null,
            'model' => null,
            'input_tokens' => 0,
            'output_tokens' => 0,
        ];
    }

    public function fromUser(): static
    {
        return $this->state(['from_user' => true]);
    }

    public function answered(): static
    {
        return $this->state(['answered_at' => now()]);
    }

    /**
     * A reply written by the AI, as opposed to a scripted message.
     */
    public function aiReply(string $model = 'claude-haiku-5-5'): static
    {
        return $this->state(['from_user' => false, 'model' => $model]);
    }
}
