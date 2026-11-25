<?php

namespace App\Services\Courses;

use JsonException;
use RuntimeException;

/**
 * Reads the course files from `config('sprintlab.courses_path')`.
 *
 * A file that breaks the format is skipped and reported: it never breaks a page.
 */
class CourseLibrary
{
    public function __construct(private readonly CourseValidator $validator) {}

    /**
     * Every valid course, sorted by title.
     *
     * @return list<Course>
     */
    public function all(): array
    {
        $courses = [];

        foreach (glob($this->path().'/*.json') ?: [] as $file) {
            $course = $this->load($file);

            if ($course !== null) {
                $courses[] = $course;
            }
        }

        usort($courses, fn (Course $a, Course $b): int => strcasecmp($a->title, $b->title));

        return $courses;
    }

    public function find(string $slug): ?Course
    {
        // The slug becomes part of a file name: nothing but a-z, 0-9 and dashes.
        if (preg_match(CourseValidator::SLUG_PATTERN, $slug) !== 1) {
            return null;
        }

        $file = $this->path().'/'.$slug.'.json';

        return is_file($file) ? $this->load($file) : null;
    }

    private function path(): string
    {
        return rtrim((string) config('sprintlab.courses_path'), '/\\');
    }

    private function load(string $file): ?Course
    {
        $name = basename($file);

        try {
            $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            report(new RuntimeException("Course file {$name} is skipped: it is not valid JSON ({$e->getMessage()}).", 0, $e));

            return null;
        }

        $problems = $this->validator->validate($data);

        if (is_array($data) && ($data['slug'] ?? null) !== basename($file, '.json')) {
            $problems[] = 'slug must match the file name.';
        }

        if ($problems !== []) {
            report(new RuntimeException("Course file {$name} is skipped: ".implode(' ', $problems)));

            return null;
        }

        /** @var array<string, mixed> $data */
        return Course::fromArray($data);
    }
}
