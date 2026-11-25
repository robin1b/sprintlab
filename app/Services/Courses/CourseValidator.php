<?php

namespace App\Services\Courses;

/**
 * Checks one decoded course file against the format in docs/courses.md and
 * returns the problems it finds. An empty list means the file is valid.
 *
 * The counts the format asks for (6 lessons, 10 to 12 questions) are only
 * checked in strict mode: that is for the real files, small test fixtures
 * may be shorter.
 */
class CourseValidator
{
    public const SLUG_PATTERN = '/^[a-z0-9-]+$/';

    public const LESSONS = 6;

    public const QUESTIONS_MIN = 10;

    public const QUESTIONS_MAX = 12;

    private const OPTIONS = 4;

    private const LANGUAGES = ['json', 'js', 'php', 'vue', 'ts', 'bash', 'text'];

    private const NOTE_LABELS = [
        'What you already know from Laravel',
        'Say it like this in an interview',
    ];

    /** @var list<string> */
    private array $problems = [];

    /**
     * @return list<string>
     */
    public function validate(mixed $data, bool $strict = false): array
    {
        $this->problems = [];

        if (! is_array($data) || array_is_list($data)) {
            return ['The file must hold one JSON object.'];
        }

        $this->slug($data['slug'] ?? null, 'slug');

        if (($data['locale'] ?? null) !== 'en') {
            $this->problems[] = 'locale must be "en".';
        }

        $this->text($data['title'] ?? null, 'title');
        $this->text($data['summary'] ?? null, 'summary');
        $this->minutes($data['minutes'] ?? null, 'minutes');

        $updated = $data['updated'] ?? null;

        if (! is_string($updated) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $updated, $date) !== 1 || ! checkdate((int) $date[2], (int) $date[3], (int) $date[1])) {
            $this->problems[] = 'updated must be a date like 2026-11-25.';
        }

        $lessonSlugs = $this->lessons($data['lessons'] ?? null, $strict);
        $this->exercise($data['exercise'] ?? null);
        $this->quiz($data['quiz'] ?? null, $lessonSlugs, $strict);
        $this->sources($data['sources'] ?? null);

        return $this->problems;
    }

    /**
     * @return list<string> the lesson slugs
     */
    private function lessons(mixed $lessons, bool $strict): array
    {
        if (! is_array($lessons) || ! array_is_list($lessons) || $lessons === []) {
            $this->problems[] = 'lessons must be a list with at least one lesson.';

            return [];
        }

        if ($strict && count($lessons) !== self::LESSONS) {
            $this->problems[] = 'There must be exactly '.self::LESSONS.' lessons, found '.count($lessons).'.';
        }

        $slugs = [];

        foreach ($lessons as $index => $lesson) {
            $where = 'lessons.'.$index;

            if (! is_array($lesson)) {
                $this->problems[] = "{$where} must be an object.";

                continue;
            }

            $slug = $this->slug($lesson['slug'] ?? null, "{$where}.slug");

            if ($slug !== null) {
                if (in_array($slug, $slugs, true)) {
                    $this->problems[] = "{$where}.slug \"{$slug}\" is used twice.";
                }

                $slugs[] = $slug;
            }

            $this->text($lesson['title'] ?? null, "{$where}.title");
            $this->minutes($lesson['minutes'] ?? null, "{$where}.minutes");
            $this->blocks($lesson['blocks'] ?? null, "{$where}.blocks", 1);

            $labels = [];

            foreach (is_array($lesson['blocks'] ?? null) ? $lesson['blocks'] : [] as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'note' && is_string($block['label'] ?? null)) {
                    $labels[] = $block['label'];
                }
            }

            foreach (self::NOTE_LABELS as $label) {
                if (! in_array($label, $labels, true)) {
                    $this->problems[] = "{$where}.blocks needs a note with the label \"{$label}\".";
                }
            }

            $check = $lesson['check'] ?? null;

            if (! is_array($check)) {
                $this->problems[] = "{$where}.check must be an object with a question and an answer.";
            } else {
                $this->text($check['question'] ?? null, "{$where}.check.question");
                $this->text($check['answer'] ?? null, "{$where}.check.answer");
            }

            $this->blocks($lesson['deeper'] ?? null, "{$where}.deeper", 2, 4);
        }

        return $slugs;
    }

    private function exercise(mixed $exercise): void
    {
        if (! is_array($exercise)) {
            $this->problems[] = 'exercise must be an object.';

            return;
        }

        $this->text($exercise['title'] ?? null, 'exercise.title');
        $this->minutes($exercise['minutes'] ?? null, 'exercise.minutes');
        $this->text($exercise['intro'] ?? null, 'exercise.intro');
        $this->strings($exercise['steps'] ?? null, 'exercise.steps');

        $starter = $exercise['starter'] ?? null;

        if ($starter !== null) {
            if (! is_array($starter)) {
                $this->problems[] = 'exercise.starter must be null or an object.';
            } else {
                $this->language($starter['language'] ?? null, 'exercise.starter.language');
                $this->text($starter['code'] ?? null, 'exercise.starter.code');
            }
        }

        $this->blocks($exercise['solution'] ?? null, 'exercise.solution', 1);
    }

    /**
     * @param  list<string>  $lessonSlugs
     */
    private function quiz(mixed $quiz, array $lessonSlugs, bool $strict): void
    {
        if (! is_array($quiz) || ! array_is_list($quiz) || $quiz === []) {
            $this->problems[] = 'quiz must be a list with at least one question.';

            return;
        }

        if ($strict && (count($quiz) < self::QUESTIONS_MIN || count($quiz) > self::QUESTIONS_MAX)) {
            $this->problems[] = 'The quiz needs '.self::QUESTIONS_MIN.' to '.self::QUESTIONS_MAX.' questions, found '.count($quiz).'.';
        }

        foreach ($quiz as $index => $question) {
            $where = 'quiz.'.$index;

            if (! is_array($question)) {
                $this->problems[] = "{$where} must be an object.";

                continue;
            }

            $this->text($question['question'] ?? null, "{$where}.question");
            $this->text($question['explanation'] ?? null, "{$where}.explanation");

            $options = $question['options'] ?? null;

            if (! is_array($options) || ! array_is_list($options) || count($options) !== self::OPTIONS) {
                $this->problems[] = "{$where}.options must be a list of exactly ".self::OPTIONS.' texts.';
            } else {
                $this->strings($options, "{$where}.options");
            }

            $answer = $question['answer'] ?? null;

            if (! is_int($answer) || $answer < 0 || $answer >= self::OPTIONS) {
                $this->problems[] = "{$where}.answer must be a whole number from 0 to ".(self::OPTIONS - 1).'.';
            }

            $lesson = $question['lesson'] ?? null;

            if (! is_string($lesson) || ! in_array($lesson, $lessonSlugs, true)) {
                $this->problems[] = "{$where}.lesson must be the slug of one of the lessons.";
            }
        }
    }

    private function sources(mixed $sources): void
    {
        if (! is_array($sources) || ! array_is_list($sources)) {
            $this->problems[] = 'sources must be a list.';

            return;
        }

        foreach ($sources as $index => $source) {
            $where = 'sources.'.$index;

            if (! is_array($source)) {
                $this->problems[] = "{$where} must be an object.";

                continue;
            }

            $this->text($source['title'] ?? null, "{$where}.title");

            if (! is_string($source['url'] ?? null) || ! str_starts_with($source['url'], 'https://')) {
                $this->problems[] = "{$where}.url must start with https://.";
            }
        }
    }

    private function blocks(mixed $blocks, string $where, int $min, ?int $max = null): void
    {
        if (! is_array($blocks) || ! array_is_list($blocks) || count($blocks) < $min || ($max !== null && count($blocks) > $max)) {
            $this->problems[] = $max === null
                ? "{$where} must be a list of at least {$min} block(s)."
                : "{$where} must be a list of {$min} to {$max} blocks.";

            return;
        }

        foreach ($blocks as $index => $block) {
            $this->block($block, "{$where}.{$index}");
        }
    }

    private function block(mixed $block, string $where): void
    {
        if (! is_array($block)) {
            $this->problems[] = "{$where} must be an object.";

            return;
        }

        switch ($block['type'] ?? null) {
            case 'text':
            case 'heading':
                $this->text($block['text'] ?? null, "{$where}.text");
                break;
            case 'list':
            case 'steps':
                $this->strings($block['items'] ?? null, "{$where}.items");
                break;
            case 'code':
                $this->language($block['language'] ?? null, "{$where}.language");
                $this->text($block['code'] ?? null, "{$where}.code");

                if (array_key_exists('caption', $block) && ! is_string($block['caption'])) {
                    $this->problems[] = "{$where}.caption must be a text.";
                }

                break;
            case 'table':
                $this->table($block, $where);
                break;
            case 'note':
                $this->text($block['label'] ?? null, "{$where}.label");
                $this->text($block['text'] ?? null, "{$where}.text");
                break;
            default:
                $this->problems[] = "{$where}.type is not a known block type.";
        }
    }

    /**
     * @param  array<mixed>  $block
     */
    private function table(array $block, string $where): void
    {
        $this->strings($block['headers'] ?? null, "{$where}.headers");

        $columns = is_array($block['headers'] ?? null) ? count($block['headers']) : 0;
        $rows = $block['rows'] ?? null;

        if (! is_array($rows) || ! array_is_list($rows) || $rows === []) {
            $this->problems[] = "{$where}.rows must be a list with at least one row.";

            return;
        }

        foreach ($rows as $index => $row) {
            if (! is_array($row) || ! array_is_list($row) || count($row) !== $columns) {
                $this->problems[] = "{$where}.rows.{$index} must have {$columns} cells.";

                continue;
            }

            $this->strings($row, "{$where}.rows.{$index}");
        }
    }

    private function slug(mixed $value, string $where): ?string
    {
        if (! is_string($value) || preg_match(self::SLUG_PATTERN, $value) !== 1) {
            $this->problems[] = "{$where} must use only a-z, 0-9 and dashes.";

            return null;
        }

        return $value;
    }

    private function text(mixed $value, string $where): void
    {
        if (! is_string($value) || trim($value) === '') {
            $this->problems[] = "{$where} must be a text that is not empty.";
        }
    }

    private function minutes(mixed $value, string $where): void
    {
        if (! is_int($value) || $value < 1) {
            $this->problems[] = "{$where} must be a whole number of minutes.";
        }
    }

    private function language(mixed $value, string $where): void
    {
        if (! is_string($value) || ! in_array($value, self::LANGUAGES, true)) {
            $this->problems[] = "{$where} must be one of: ".implode(', ', self::LANGUAGES).'.';
        }
    }

    private function strings(mixed $values, string $where): void
    {
        if (! is_array($values) || ! array_is_list($values) || $values === []) {
            $this->problems[] = "{$where} must be a list with at least one text.";

            return;
        }

        foreach ($values as $index => $value) {
            if (! is_string($value) || trim($value) === '') {
                $this->problems[] = "{$where}.{$index} must be a text that is not empty.";
            }
        }
    }
}
