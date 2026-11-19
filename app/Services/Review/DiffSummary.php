<?php

namespace App\Services\Review;

use App\Services\GitHub\Diff;

/**
 * Prepares the changed files of a pull request for the model.
 */
final class DiffSummary
{
    // Generated or third-party files: reviewing them teaches nothing.
    private const SKIPPED = [
        '#(^|/)(vendor|node_modules)/#',
        '#^public/build/#',
        '#(^|/)(composer\.lock|package-lock\.json|pnpm-lock\.yaml|yarn\.lock)$#',
        '#\.min\.(js|css)$#',
    ];

    /**
     * @param  string  $text  the numbered diff of all reviewable files
     * @param  array<string, list<int>>  $commentableLines  per file, the lines a comment may point at
     */
    private function __construct(
        public readonly string $text,
        public readonly array $commentableLines,
        public readonly bool $truncated,
    ) {}

    /**
     * @param  list<array{filename: string, status: string, additions: int, deletions: int, patch: string|null}>  $files
     */
    public static function fromFiles(array $files, int $maxCharacters): self
    {
        $parts = [];
        $commentable = [];
        $length = 0;
        $truncated = false;

        foreach ($files as $file) {
            if ($file['patch'] === null || self::isSkipped($file['filename'])) {
                continue;
            }

            $part = sprintf(
                "File: %s (%s, +%d -%d)\n%s",
                $file['filename'],
                $file['status'],
                $file['additions'],
                $file['deletions'],
                Diff::numbered($file['patch']),
            );

            if ($length + strlen($part) > $maxCharacters) {
                $truncated = true;

                break;
            }

            $parts[] = $part;
            $length += strlen($part);
            $commentable[$file['filename']] = Diff::commentableLines($file['patch']);
        }

        return new self(implode("\n\n", $parts), $commentable, $truncated);
    }

    public function isEmpty(): bool
    {
        return $this->commentableLines === [];
    }

    public function allows(string $path, int $line): bool
    {
        return in_array($line, $this->commentableLines[$path] ?? [], true);
    }

    private static function isSkipped(string $filename): bool
    {
        foreach (self::SKIPPED as $pattern) {
            if (preg_match($pattern, $filename) === 1) {
                return true;
            }
        }

        return false;
    }
}
