<?php

namespace Database\Factories;

use App\Models\CourseQuizAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourseQuizAttempt>
 */
class CourseQuizAttemptFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'course' => 'vue',
            'score' => 2,
            'total' => 3,
            'answers' => [0, 1, 0],
        ];
    }
}
