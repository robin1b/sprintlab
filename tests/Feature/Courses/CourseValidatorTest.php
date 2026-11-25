<?php

namespace Tests\Feature\Courses;

use App\Services\Courses\CourseValidator;
use Tests\TestCase;

class CourseValidatorTest extends TestCase
{
    public function test_the_fixtures_are_valid()
    {
        foreach (['vue', 'n8n'] as $slug) {
            $this->assertSame([], (new CourseValidator)->validate($this->fixture($slug)), $slug);
        }
    }

    public function test_the_fixtures_use_every_block_type()
    {
        $types = [];

        foreach (['vue', 'n8n'] as $slug) {
            foreach ($this->fixture($slug)['lessons'] as $lesson) {
                foreach ($lesson['blocks'] as $block) {
                    $types[$block['type']] = true;
                }
            }
        }

        $this->assertEqualsCanonicalizing(['text', 'heading', 'list', 'steps', 'code', 'table', 'note'], array_keys($types));
    }

    public function test_a_file_that_is_not_an_object_is_refused()
    {
        $this->assertNotSame([], (new CourseValidator)->validate('text'));
        $this->assertNotSame([], (new CourseValidator)->validate([]));
        $this->assertNotSame([], (new CourseValidator)->validate([1, 2]));
    }

    public function test_each_break_of_the_format_is_reported()
    {
        $cases = [
            'slug' => fn (array $c) => ['slug' => 'Not A Slug'] + $c,
            'locale' => fn (array $c) => ['locale' => 'nl'] + $c,
            'lessons.0.blocks' => function (array $c) {
                $c['lessons'][0]['blocks'] = [['type' => 'video', 'text' => 'x']];

                return $c;
            },
            'lessons.1.slug' => function (array $c) {
                $c['lessons'][1]['slug'] = $c['lessons'][0]['slug'];

                return $c;
            },
            'deeper' => function (array $c) {
                $c['lessons'][0]['deeper'] = [$c['lessons'][0]['deeper'][0]];

                return $c;
            },
            'label' => function (array $c) {
                $c['lessons'][0]['blocks'] = [['type' => 'text', 'text' => 'No notes here.']];

                return $c;
            },
            'quiz.0.options' => function (array $c) {
                $c['quiz'][0]['options'] = ['a', 'b'];

                return $c;
            },
            'quiz.1.lesson' => function (array $c) {
                $c['quiz'][1]['lesson'] = 'missing';

                return $c;
            },
            'quiz.2.answer' => function (array $c) {
                $c['quiz'][2]['answer'] = '1';

                return $c;
            },
            'sources.0.url' => function (array $c) {
                $c['sources'][0]['url'] = 'http://insecure.example.com';

                return $c;
            },
            'exercise.starter.language' => function (array $c) {
                $c['exercise']['starter'] = ['language' => 'cobol', 'code' => 'x'];

                return $c;
            },
            'rows.0' => function (array $c) {
                $c['lessons'][0]['blocks'][] = ['type' => 'table', 'headers' => ['a', 'b'], 'rows' => [['only one']]];

                return $c;
            },
        ];

        foreach ($cases as $expected => $change) {
            $problems = (new CourseValidator)->validate($change($this->fixture('vue')));

            $this->assertNotSame([], $problems, $expected);
            $this->assertNotEmpty(
                array_filter($problems, fn (string $problem) => str_contains($problem, $expected)),
                "{$expected}: ".implode(' | ', $problems),
            );
        }
    }

    public function test_strict_mode_asks_for_six_lessons_and_ten_to_twelve_questions()
    {
        $problems = (new CourseValidator)->validate($this->fixture('vue'), strict: true);

        $this->assertCount(2, $problems);
        $this->assertStringContainsString('6 lessons', $problems[0]);
        $this->assertStringContainsString('10 to 12 questions', $problems[1]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $slug): array
    {
        return json_decode((string) file_get_contents(base_path("tests/Fixtures/courses/{$slug}.json")), true);
    }
}
