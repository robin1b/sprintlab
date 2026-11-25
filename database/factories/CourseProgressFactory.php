<?php

namespace Database\Factories;

use App\Enums\LessonStatus;
use App\Models\CourseProgress;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourseProgress>
 */
class CourseProgressFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'course' => 'vue',
            'lesson' => 'components',
            'status' => LessonStatus::Understood,
        ];
    }

    public function notUnderstood(): static
    {
        return $this->state(['status' => LessonStatus::NotUnderstood]);
    }
}
