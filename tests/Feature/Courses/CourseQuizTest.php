<?php

namespace Tests\Feature\Courses;

use App\Models\CourseQuizAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseQuizTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sprintlab.courses_path' => base_path('tests/Fixtures/courses')]);
    }

    public function test_guests_are_sent_to_sign_in()
    {
        $this->post(route('courses.quiz', 'vue'), ['answers' => [0, 1, 2]])->assertRedirect(route('login'));

        $this->assertDatabaseCount('course_quiz_attempts', 0);
    }

    public function test_the_score_is_worked_out_from_the_answers()
    {
        $user = User::factory()->create();

        // The right answers of the vue fixture are 0, 1 and 2.
        $this->actingAs($user)->post(route('courses.quiz', 'vue'), ['answers' => [0, 1, 3]])->assertRedirect();

        $attempt = CourseQuizAttempt::query()->sole();
        $this->assertSame($user->id, $attempt->user_id);
        $this->assertSame('vue', $attempt->course);
        $this->assertSame(2, $attempt->score);
        $this->assertSame(3, $attempt->total);
        $this->assertSame([0, 1, 3], $attempt->answers);
    }

    public function test_a_score_from_the_browser_is_ignored()
    {
        $this->actingAs(User::factory()->create())
            ->post(route('courses.quiz', 'vue'), ['answers' => [3, 3, 3], 'score' => 3, 'total' => 99])
            ->assertRedirect();

        $attempt = CourseQuizAttempt::query()->sole();
        $this->assertSame(0, $attempt->score);
        $this->assertSame(3, $attempt->total);
    }

    public function test_every_try_is_kept()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('courses.quiz', 'vue'), ['answers' => [3, 3, 3]]);
        $this->actingAs($user)->post(route('courses.quiz', 'vue'), ['answers' => [0, 1, 2]]);

        $this->assertSame([0, 3], CourseQuizAttempt::query()->orderBy('id')->pluck('score')->all());
    }

    public function test_a_wrong_number_of_answers_is_a_validation_error()
    {
        $user = User::factory()->create();

        foreach ([[], [0, 1], [0, 1, 2, 3]] as $answers) {
            $this->actingAs($user)->post(route('courses.quiz', 'vue'), ['answers' => $answers])->assertSessionHasErrors('answers');
        }

        $this->actingAs($user)->post(route('courses.quiz', 'vue'))->assertSessionHasErrors('answers');
        $this->assertDatabaseCount('course_quiz_attempts', 0);
    }

    public function test_answers_out_of_range_or_not_numbers_are_a_validation_error()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('courses.quiz', 'vue'), ['answers' => [0, 4, 0]])->assertSessionHasErrors('answers.1');
        $this->actingAs($user)->post(route('courses.quiz', 'vue'), ['answers' => [0, -1, 0]])->assertSessionHasErrors('answers.1');
        $this->actingAs($user)->post(route('courses.quiz', 'vue'), ['answers' => [0, 'a', 0]])->assertSessionHasErrors('answers.1');
        $this->actingAs($user)->post(route('courses.quiz', 'vue'), ['answers' => [0, null, 0]])->assertSessionHasErrors('answers.1');
        $this->actingAs($user)->post(route('courses.quiz', 'vue'), ['answers' => 'zero'])->assertSessionHasErrors('answers');

        $this->assertDatabaseCount('course_quiz_attempts', 0);
    }

    public function test_an_unknown_course_is_a_404()
    {
        $this->actingAs(User::factory()->create())
            ->post(route('courses.quiz', 'nope'), ['answers' => [0]])
            ->assertNotFound();
    }

    public function test_attempts_go_with_the_account()
    {
        $user = User::factory()->create();
        CourseQuizAttempt::factory()->for($user)->create();

        $user->delete();

        $this->assertDatabaseCount('course_quiz_attempts', 0);
    }
}
