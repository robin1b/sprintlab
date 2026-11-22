<?php

namespace Database\Factories;

use App\Enums\AssessmentStatus;
use App\Models\ReviewAssessment;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReviewAssessment>
 */
class ReviewAssessmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            // The pull request has no factory: pass one in when a test needs it.
            'pull_request_id' => null,
            'github_review_id' => fake()->unique()->numberBetween(1, 999999999),
            'reviewer_login' => 'robin',
            'status' => AssessmentStatus::Pending,
        ];
    }

    public function status(AssessmentStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
