<?php

namespace Tests\Feature\Courses;

use App\Models\CourseProgress;
use App\Models\CourseQuizAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CoursePagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['sprintlab.courses_path' => base_path('tests/Fixtures/courses')]);
    }

    public function test_a_guest_sees_the_courses_sorted_by_title_without_progress()
    {
        $this->get('/courses')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('courses/Index')
                ->has('courses', 2)
                ->where('courses.0.slug', 'n8n')
                ->where('courses.0.title', 'n8n in an hour')
                ->where('courses.0.minutes', 40)
                ->where('courses.0.lessons', 2)
                ->where('courses.0.understood', null)
                ->where('courses.1.slug', 'vue')
                ->has('courses.0.summary'));
    }

    public function test_a_signed_in_user_sees_how_many_lessons_they_understood()
    {
        $user = User::factory()->create();
        CourseProgress::factory()->for($user)->create(['course' => 'vue', 'lesson' => 'components']);
        CourseProgress::factory()->for($user)->notUnderstood()->create(['course' => 'vue', 'lesson' => 'reactivity']);
        CourseProgress::factory()->create(['course' => 'vue', 'lesson' => 'reactivity']);
        // A lesson that is no longer in the file does not count.
        CourseProgress::factory()->for($user)->create(['course' => 'vue', 'lesson' => 'removed']);

        $this->actingAs($user)->get(route('courses.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('courses.0.slug', 'n8n')
                ->where('courses.0.understood', 0)
                ->where('courses.1.slug', 'vue')
                ->where('courses.1.understood', 1));
    }

    public function test_the_index_is_empty_without_course_files()
    {
        config(['sprintlab.courses_path' => sys_get_temp_dir().'/no-such-courses-folder']);

        $this->get('/courses')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('courses/Index')->has('courses', 0));
    }

    public function test_a_guest_gets_the_whole_course_without_progress()
    {
        $this->get(route('courses.show', 'vue'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('courses/Show')
                ->where('course.slug', 'vue')
                ->has('course.lessons', 2)
                ->where('course.lessons.0.slug', 'components')
                ->has('course.lessons.0.deeper', 2)
                ->has('course.exercise.solution')
                ->has('course.quiz', 3)
                ->where('course.quiz.0.answer', 0)
                ->has('course.quiz.0.explanation')
                ->where('progress', null)
                ->where('lastAttempt', null));
    }

    public function test_a_signed_in_user_gets_progress_and_the_last_attempt()
    {
        $user = User::factory()->create();
        CourseProgress::factory()->for($user)->create(['course' => 'vue', 'lesson' => 'components']);
        CourseProgress::factory()->for($user)->notUnderstood()->create(['course' => 'vue', 'lesson' => 'reactivity']);
        CourseProgress::factory()->for($user)->create(['course' => 'n8n', 'lesson' => 'editor']);
        CourseQuizAttempt::factory()->for($user)->create(['course' => 'vue', 'score' => 1, 'total' => 3]);
        CourseQuizAttempt::factory()->for($user)->create(['course' => 'vue', 'score' => 3, 'total' => 3]);

        $this->actingAs($user)->get(route('courses.show', 'vue'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('progress', ['components' => 'understood', 'reactivity' => 'not_understood'])
                ->where('lastAttempt.score', 3)
                ->where('lastAttempt.total', 3)
                ->has('lastAttempt.at'));
    }

    public function test_a_new_user_gets_an_empty_progress_object()
    {
        $response = $this->actingAs(User::factory()->create())->get(route('courses.show', 'vue'));

        // Empty, but an object (`{}`): the page reads it as a record.
        $this->assertStringContainsString('"progress":{}', json_encode($response->viewData('page')['props']));
    }

    public function test_an_unknown_course_is_a_404()
    {
        $this->get('/courses/nope')->assertNotFound();
        $this->get('/courses/Vue')->assertNotFound();
        $this->get('/courses/vue.json')->assertNotFound();
        $this->get('/courses/..%2Fvue')->assertNotFound();
    }
}
