<?php

namespace App\Services\Courses;

/**
 * One course, read from its file. Read-only.
 *
 * Blocks are kept as the validated arrays of the file: see docs/courses.md.
 *
 * @phpstan-type Block array<string, mixed>
 * @phpstan-type Starter array{language: string, code: string}
 * @phpstan-type Exercise array{title: string, minutes: int, intro: string, steps: list<string>, starter: Starter|null, solution: list<Block>}
 * @phpstan-type Question array{question: string, options: list<string>, answer: int, explanation: string, lesson: string}
 * @phpstan-type Lesson array{slug: string, title: string, minutes: int, blocks: list<Block>, check: array{question: string, answer: string}, deeper: list<Block>}
 * @phpstan-type Source array{title: string, url: string}
 */
final readonly class Course
{
    /**
     * @param  list<Lesson>  $lessons
     * @param  Exercise  $exercise
     * @param  list<Question>  $quiz
     * @param  list<Source>  $sources
     */
    public function __construct(
        public string $slug,
        public string $locale,
        public string $title,
        public string $summary,
        public int $minutes,
        public string $updated,
        public array $lessons,
        public array $exercise,
        public array $quiz,
        public array $sources,
    ) {}

    /**
     * Build a course from a file that passed the validator.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        /** @var list<Lesson> $lessons */
        $lessons = $data['lessons'];
        /** @var Exercise $exercise */
        $exercise = $data['exercise'];
        /** @var list<Question> $quiz */
        $quiz = $data['quiz'];
        /** @var list<Source> $sources */
        $sources = $data['sources'];

        return new self(
            slug: (string) $data['slug'],
            locale: (string) $data['locale'],
            title: (string) $data['title'],
            summary: (string) $data['summary'],
            minutes: (int) $data['minutes'],
            updated: (string) $data['updated'],
            lessons: $lessons,
            exercise: $exercise,
            quiz: $quiz,
            sources: $sources,
        );
    }

    /**
     * @return Lesson|null
     */
    public function lesson(string $slug): ?array
    {
        foreach ($this->lessons as $lesson) {
            if ($lesson['slug'] === $slug) {
                return $lesson;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function lessonSlugs(): array
    {
        return array_column($this->lessons, 'slug');
    }

    /**
     * The whole course, in the shape of the file.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'locale' => $this->locale,
            'title' => $this->title,
            'summary' => $this->summary,
            'minutes' => $this->minutes,
            'updated' => $this->updated,
            'lessons' => $this->lessons,
            'exercise' => $this->exercise,
            'quiz' => $this->quiz,
            'sources' => $this->sources,
        ];
    }
}
