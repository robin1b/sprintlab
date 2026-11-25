<?php

namespace Tests\Feature\Courses;

use App\Enums\LessonStatus;
use App\Models\CourseProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseProgressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sprintlab.courses_path' => base_path('tests/Fixtures/courses')]);
    }

    public function test_guests_are_sent_to_sign_in()
    {
        $this->post(route('courses.progress', ['vue', 'components']), ['status' => 'understood'])
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('course_progress', 0);
    }

    public function test_a_status_is_saved()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('courses.progress', ['vue', 'components']), ['status' => 'understood'])
            ->assertRedirect();

        $progress = CourseProgress::query()->sole();
        $this->assertSame($user->id, $progress->user_id);
        $this->assertSame('vue', $progress->course);
        $this->assertSame('components', $progress->lesson);
        $this->assertSame(LessonStatus::Understood, $progress->status);
    }

    public function test_a_second_status_updates_the_row()
    {
        $user = User::factory()->create();
        CourseProgress::factory()->for($user)->create(['course' => 'vue', 'lesson' => 'components']);

        $this->actingAs($user)
            ->post(route('courses.progress', ['vue', 'components']), ['status' => 'not_understood'])
            ->assertRedirect();

        $this->assertSame(LessonStatus::NotUnderstood, CourseProgress::query()->sole()->status);
    }

    public function test_progress_of_one_user_does_not_touch_another()
    {
        $other = CourseProgress::factory()->create(['course' => 'vue', 'lesson' => 'components']);

        $this->actingAs(User::factory()->create())
            ->post(route('courses.progress', ['vue', 'components']), ['status' => 'not_understood']);

        $this->assertSame(LessonStatus::Understood, $other->fresh()->status);
        $this->assertDatabaseCount('course_progress', 2);
    }

    public function test_a_wrong_status_is_a_validation_error()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('courses.progress', ['vue', 'components']), ['status' => 'maybe'])
            ->assertSessionHasErrors('status');

        $this->actingAs($user)
            ->post(route('courses.progress', ['vue', 'components']))
            ->assertSessionHasErrors('status');

        $this->assertDatabaseCount('course_progress', 0);
    }

    public function test_an_unknown_course_or_lesson_is_a_404_even_with_a_wrong_status()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('courses.progress', ['nope', 'components']), ['status' => 'understood'])->assertNotFound();
        $this->actingAs($user)->post(route('courses.progress', ['vue', 'nope']), ['status' => 'understood'])->assertNotFound();
        $this->actingAs($user)->post(route('courses.progress', ['vue', 'nope']), ['status' => 'maybe'])->assertNotFound();
        // A lesson of another course is not a lesson of this one.
        $this->actingAs($user)->post(route('courses.progress', ['vue', 'editor']), ['status' => 'understood'])->assertNotFound();

        $this->assertDatabaseCount('course_progress', 0);
    }

    public function test_progress_goes_with_the_account()
    {
        $user = User::factory()->create();
        CourseProgress::factory()->for($user)->create();

        $user->delete();

        $this->assertDatabaseCount('course_progress', 0);
    }
}
