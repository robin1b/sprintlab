<?php

namespace Tests\Feature\Courses;

use App\Services\Courses\CourseLibrary;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Exceptions;
use RuntimeException;
use Tests\TestCase;

class CourseLibraryTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->folder = sys_get_temp_dir().'/sprintlab-courses-'.bin2hex(random_bytes(4));
        (new Filesystem)->makeDirectory($this->folder);
        config(['sprintlab.courses_path' => $this->folder]);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->folder);

        parent::tearDown();
    }

    public function test_it_reads_the_valid_files_sorted_by_title()
    {
        $this->copyFixture('vue');
        $this->copyFixture('n8n');

        $library = $this->app->make(CourseLibrary::class);

        $this->assertSame(['n8n', 'vue'], array_map(fn ($course) => $course->slug, $library->all()));
        $this->assertSame('Vue in an hour', $library->find('vue')?->title);
        $this->assertSame(['components', 'reactivity'], $library->find('vue')?->lessonSlugs());
    }

    public function test_an_unknown_or_unsafe_slug_finds_nothing()
    {
        $this->copyFixture('vue');

        $library = $this->app->make(CourseLibrary::class);

        $this->assertNull($library->find('nope'));
        $this->assertNull($library->find('../vue'));
        $this->assertNull($library->find(''));
    }

    public function test_a_file_that_is_not_json_is_skipped_and_reported()
    {
        Exceptions::fake();
        $this->copyFixture('vue');
        file_put_contents($this->folder.'/broken.json', '{ not json');

        $library = $this->app->make(CourseLibrary::class);

        $this->assertSame(['vue'], array_map(fn ($course) => $course->slug, $library->all()));
        $this->assertNull($library->find('broken'));
        Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'broken.json'));
    }

    public function test_a_file_that_breaks_the_format_is_skipped_and_reported()
    {
        Exceptions::fake();
        $this->copyFixture('vue');

        $course = $this->fixture('n8n');
        $course['quiz'][0]['answer'] = 7;
        file_put_contents($this->folder.'/n8n.json', json_encode($course));

        $library = $this->app->make(CourseLibrary::class);

        $this->assertSame(['vue'], array_map(fn ($course) => $course->slug, $library->all()));
        $this->assertNull($library->find('n8n'));
        Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'n8n.json') && str_contains($e->getMessage(), 'quiz.0.answer'));
    }

    public function test_a_slug_that_differs_from_the_file_name_is_skipped()
    {
        Exceptions::fake();
        file_put_contents($this->folder.'/other.json', json_encode($this->fixture('vue')));

        $this->assertSame([], $this->app->make(CourseLibrary::class)->all());
        Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'slug must match the file name'));
    }

    public function test_a_broken_file_never_breaks_a_page()
    {
        Exceptions::fake();
        $this->copyFixture('vue');
        file_put_contents($this->folder.'/broken.json', '[]');

        $this->get('/courses')->assertOk();
        $this->get('/courses/broken')->assertNotFound();
        $this->get('/courses/vue')->assertOk();
    }

    private function copyFixture(string $slug): void
    {
        copy(base_path("tests/Fixtures/courses/{$slug}.json"), "{$this->folder}/{$slug}.json");
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $slug): array
    {
        return json_decode((string) file_get_contents(base_path("tests/Fixtures/courses/{$slug}.json")), true);
    }
}
