<?php

namespace App\Services\GitHub;

/**
 * Reads the "patch" text GitHub returns for a changed file.
 *
 * A review comment can only be placed on a line that is part of the diff,
 * so we need to know which line numbers of the new file appear in it.
 */
final class Diff
{
    /**
     * @return list<array{type: 'hunk'|'add'|'del'|'context', line: int|null, text: string}>
     */
    public static function parse(string $patch): array
    {
        $entries = [];
        $newLine = 0;

        foreach (explode("\n", $patch) as $raw) {
            if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,\d+)? @@/', $raw, $matches) === 1) {
                $newLine = (int) $matches[1];
                $entries[] = ['type' => 'hunk', 'line' => null, 'text' => $raw];

                continue;
            }

            // Lines before the first hunk header and "\ No newline at end of file".
            if ($newLine === 0 || str_starts_with($raw, '\\')) {
                continue;
            }

            $marker = $raw[0] ?? ' ';
            $text = substr($raw, 1);

            if ($marker === '+') {
                $entries[] = ['type' => 'add', 'line' => $newLine++, 'text' => $text];
            } elseif ($marker === '-') {
                $entries[] = ['type' => 'del', 'line' => null, 'text' => $text];
            } else {
                $entries[] = ['type' => 'context', 'line' => $newLine++, 'text' => $text];
            }
        }

        return $entries;
    }

    /**
     * The line numbers (in the new version of the file) a comment may point at.
     *
     * @return list<int>
     */
    public static function commentableLines(string $patch): array
    {
        $lines = [];

        foreach (self::parse($patch) as $entry) {
            if ($entry['line'] !== null) {
                $lines[] = $entry['line'];
            }
        }

        return $lines;
    }

    /**
     * The same diff with the new line number in front of every line, so the
     * model can refer to exact lines.
     */
    public static function numbered(string $patch): string
    {
        $output = [];

        foreach (self::parse($patch) as $entry) {
            $output[] = match ($entry['type']) {
                'hunk' => $entry['text'],
                'add' => sprintf('+ %5d | %s', $entry['line'], $entry['text']),
                'del' => sprintf('- %5s | %s', '', $entry['text']),
                'context' => sprintf('  %5d | %s', $entry['line'], $entry['text']),
            };
        }

        return implode("\n", $output);
    }
}
