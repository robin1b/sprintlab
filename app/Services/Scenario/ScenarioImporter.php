<?php

namespace App\Services\Scenario;

use App\Enums\Colleague;
use App\Enums\ScenarioAction;
use App\Enums\ScenarioTrigger;
use App\Enums\TicketType;
use App\Models\Scenario;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;

/**
 * Reads a scenario JSON file into the database.
 *
 * Scenarios are upserted by slug, their tickets and events by key. Rows are
 * never deleted: a running sprint may still point at them.
 */
class ScenarioImporter
{
    private const LEVELS = ['junior', 'medior', 'senior'];

    private const SEVERITIES = ['low', 'medium', 'high'];

    private const MAX_PULL_REQUEST_FILES = 20;

    private const MAX_FILE_BYTES = 100 * 1024;

    // Event fields that have their own column. Everything else goes in `payload`.
    private const EVENT_COLUMNS = ['key', 'on', 'ticket', 'day', 'delay_minutes', 'action', 'colleague'];

    public function importFile(string $path): Scenario
    {
        $name = basename($path);

        if (! is_file($path)) {
            throw new InvalidArgumentException("Scenario file {$name} does not exist.");
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException("Scenario file {$name} is not valid JSON: {$exception->getMessage()}");
        }

        if (! is_array($data)) {
            throw new InvalidArgumentException("Scenario file {$name} must contain a JSON object.");
        }

        // Validate everything first, so a broken file never leaves half a scenario behind.
        $scenario = $this->validate($data, $name, dirname($path));

        return DB::transaction(fn (): Scenario => $this->store($scenario));
    }

    /**
     * Import every `*.json` file in a directory.
     *
     * @return int the number of imported scenarios
     */
    public function importDirectory(string $path): int
    {
        $count = 0;

        foreach ($this->filesIn($path) as $file) {
            $this->importFile($file);
            $count++;
        }

        return $count;
    }

    /**
     * @return list<string>
     */
    public function filesIn(string $directory): array
    {
        if (! is_dir($directory)) {
            throw new InvalidArgumentException("Scenario directory {$directory} does not exist.");
        }

        $files = glob(rtrim($directory, '/\\').DIRECTORY_SEPARATOR.'*.json');

        if ($files === false) {
            return [];
        }

        sort($files);

        return $files;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array{slug: string, title: string, level: string, summary: string|null, duration_days: int, sequence: int|null, briefing: string|null, colleague_notes: array<string, string>|null, tickets: list<array<string, mixed>>, events: list<array<string, mixed>>}
     */
    private function validate(array $data, string $file, string $directory): array
    {
        $slug = $this->requireString($data, 'slug', "scenario in {$file}");
        $where = "scenario '{$slug}' in {$file}";

        $level = $this->requireString($data, 'level', $where);
        if (! in_array($level, self::LEVELS, true)) {
            throw $this->error($where, "unknown level '{$level}' (use ".implode(', ', self::LEVELS).')');
        }

        $duration = $data['duration_days'] ?? null;
        if (! is_int($duration) || $duration < 1) {
            throw $this->error($where, "'duration_days' must be a whole number of at least 1");
        }

        $sequence = $data['sequence'] ?? null;
        if ($sequence !== null && (! is_int($sequence) || $sequence < 1)) {
            throw $this->error($where, "'sequence' must be a whole number of at least 1");
        }

        $summary = $data['summary'] ?? null;
        if ($summary !== null && ! is_string($summary)) {
            throw $this->error($where, "'summary' must be text");
        }

        $briefing = $data['briefing'] ?? null;
        if ($briefing !== null && (! is_string($briefing) || trim($briefing) === '')) {
            throw $this->error($where, "'briefing' must be text");
        }

        $colleagueNotes = $this->validateColleagueNotes($data, $where);

        if (! isset($data['tickets']) || ! is_array($data['tickets']) || ! array_is_list($data['tickets'])) {
            throw $this->error($where, "'tickets' must be a list");
        }

        $events = $data['events'] ?? [];
        if (! is_array($events) || ! array_is_list($events)) {
            throw $this->error($where, "'events' must be a list");
        }

        $tickets = [];
        $ticketKeys = [];
        $ticketTypes = [];

        foreach ($data['tickets'] as $index => $ticket) {
            $tickets[] = $validated = $this->validateTicket($ticket, $index, $duration, $ticketKeys, $where);
            $ticketTypes[$validated['key']] = $validated['type'];
        }

        $eventList = [];
        $eventKeys = [];

        foreach ($events as $index => $event) {
            $eventList[] = $this->validateEvent($event, $index, $duration, $ticketKeys, $ticketTypes, $eventKeys, $where, $directory);
        }

        return [
            'slug' => $slug,
            'title' => $this->requireString($data, 'title', $where),
            'level' => $level,
            'summary' => $summary,
            'duration_days' => $duration,
            // Always present, so removing it from the file also removes it here.
            'sequence' => $sequence,
            // Always present, so removing them from the file also removes them here.
            'briefing' => $briefing,
            'colleague_notes' => $colleagueNotes,
            'tickets' => $tickets,
            'events' => $eventList,
        ];
    }

    /**
     * @param  list<string>  $seenKeys  filled by this method, to find duplicates
     * @return array<string, mixed>
     */
    private function validateTicket(mixed $ticket, int $index, int $duration, array &$seenKeys, string $where): array
    {
        $position = 'ticket #'.($index + 1);

        if (! is_array($ticket)) {
            throw $this->error($where, "{$position} must be an object");
        }

        $key = $this->requireString($ticket, 'key', "{$where}, {$position}");
        $where = "{$where}, ticket {$key}";

        if (in_array($key, $seenKeys, true)) {
            throw $this->error($where, 'duplicate ticket key');
        }
        $seenKeys[] = $key;

        $day = $ticket['day'] ?? null;
        if (! is_int($day) || $day < 1 || $day > $duration) {
            throw $this->error($where, "'day' must be a whole number from 1 to {$duration}");
        }

        $type = $this->requireString($ticket, 'type', $where);
        if (TicketType::tryFrom($type) === null) {
            throw $this->error($where, "unknown type '{$type}'");
        }

        $reporter = $this->requireString($ticket, 'reporter', $where);
        if (Colleague::tryFrom($reporter) === null) {
            throw $this->error($where, "unknown reporter '{$reporter}'");
        }

        $criteria = $ticket['acceptance_criteria'] ?? null;
        if (! is_array($criteria) || ! array_is_list($criteria) || array_filter($criteria, 'is_string') !== $criteria) {
            throw $this->error($where, "'acceptance_criteria' must be a list of texts");
        }

        $released = $ticket['released_at_start'] ?? true;
        if (! is_bool($released)) {
            throw $this->error($where, "'released_at_start' must be true or false");
        }

        $planted = $this->validatePlantedIssues($ticket, $type, $where);

        $reviewNotes = $ticket['review_notes'] ?? null;
        if ($reviewNotes !== null) {
            if ($type === TicketType::Review->value) {
                throw $this->error($where, "a ticket of type 'review' cannot have 'review_notes'");
            }
            if (! is_string($reviewNotes) || trim($reviewNotes) === '') {
                throw $this->error($where, "'review_notes' must be text");
            }
        }

        $reviewer = $ticket['reviewer'] ?? null;
        if ($reviewer !== null) {
            if ($type === TicketType::Review->value) {
                throw $this->error($where, "a ticket of type 'review' cannot have a 'reviewer'");
            }
            if (! is_string($reviewer) || Colleague::tryFrom($reviewer) === null) {
                throw $this->error($where, 'unknown reviewer '.(is_string($reviewer) ? "'{$reviewer}'" : json_encode($reviewer)));
            }
        }

        return [
            'key' => $key,
            'day' => $day,
            'type' => $type,
            'reporter' => $reporter,
            'title' => $this->requireString($ticket, 'title', $where),
            'description' => $this->requireString($ticket, 'description', $where),
            'acceptance_criteria' => $criteria,
            'released_at_start' => $released,
            // Always present, so removing the issues from the file also removes them here.
            'planted_issues' => $planted,
            'review_notes' => $reviewNotes,
            'reviewer' => $reviewer,
        ];
    }

    /**
     * What each colleague privately knows: colleague value => text.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<string, string>|null
     */
    private function validateColleagueNotes(array $data, string $where): ?array
    {
        if (! array_key_exists('colleague_notes', $data) || $data['colleague_notes'] === null) {
            return null;
        }

        $notes = $data['colleague_notes'];
        if (! is_array($notes) || ($notes !== [] && array_is_list($notes))) {
            throw $this->error($where, "'colleague_notes' must be an object with a text per colleague");
        }

        $result = [];

        foreach ($notes as $name => $text) {
            $name = (string) $name;

            if (Colleague::tryFrom($name) === null) {
                throw $this->error($where, "'colleague_notes' has unknown colleague '{$name}'");
            }
            if (! is_string($text) || trim($text) === '') {
                throw $this->error($where, "'colleague_notes' for '{$name}' must be text");
            }

            $result[$name] = $text;
        }

        return $result === [] ? null : $result;
    }

    /**
     * The secret problems in a colleague's pull request. Only review tickets have them.
     *
     * @param  array<array-key, mixed>  $ticket
     * @return list<array{id: string, file?: string, description: string, severity: string}>|null
     */
    private function validatePlantedIssues(array $ticket, string $type, string $where): ?array
    {
        if (! array_key_exists('planted_issues', $ticket)) {
            return null;
        }

        if ($type !== TicketType::Review->value) {
            throw $this->error($where, "only a ticket of type 'review' can have 'planted_issues'");
        }

        $issues = $ticket['planted_issues'];
        if (! is_array($issues) || ! array_is_list($issues)) {
            throw $this->error($where, "'planted_issues' must be a list");
        }

        $result = [];
        $seenIds = [];

        foreach ($issues as $index => $issue) {
            $position = "{$where}, planted issue #".($index + 1);

            if (! is_array($issue)) {
                throw $this->error($where, 'planted issue #'.($index + 1).' must be an object');
            }

            $id = $this->requireString($issue, 'id', $position);
            if (in_array($id, $seenIds, true)) {
                throw $this->error($position, "duplicate id '{$id}'");
            }
            $seenIds[] = $id;

            $severity = $this->requireString($issue, 'severity', $position);
            if (! in_array($severity, self::SEVERITIES, true)) {
                throw $this->error($position, "unknown severity '{$severity}' (use ".implode(', ', self::SEVERITIES).')');
            }

            $planted = [
                'id' => $id,
                'description' => $this->requireString($issue, 'description', $position),
                'severity' => $severity,
            ];

            if (array_key_exists('file', $issue)) {
                $planted['file'] = $this->requireString($issue, 'file', $position);
            }

            $result[] = $planted;
        }

        return $result;
    }

    /**
     * @param  list<string>  $ticketKeys
     * @param  array<string, string>  $ticketTypes  ticket key => type
     * @param  list<string>  $seenKeys  filled by this method, to find duplicates
     * @return array<string, mixed>
     */
    private function validateEvent(mixed $event, int $index, int $duration, array $ticketKeys, array $ticketTypes, array &$seenKeys, string $where, string $directory): array
    {
        $position = 'event #'.($index + 1);

        if (! is_array($event)) {
            throw $this->error($where, "{$position} must be an object");
        }

        $key = $this->requireString($event, 'key', "{$where}, {$position}");
        $where = "{$where}, event {$key}";

        if (in_array($key, $seenKeys, true)) {
            throw $this->error($where, 'duplicate event key');
        }
        $seenKeys[] = $key;

        $on = $this->requireString($event, 'on', $where);
        if (ScenarioTrigger::tryFrom($on) === null) {
            throw $this->error($where, "unknown trigger '{$on}'");
        }

        $actionName = $this->requireString($event, 'action', $where);
        $action = ScenarioAction::tryFrom($actionName);
        if ($action === null) {
            throw $this->error($where, "unknown action '{$actionName}'");
        }

        $colleague = $event['colleague'] ?? null;
        if ($colleague !== null && (! is_string($colleague) || Colleague::tryFrom($colleague) === null)) {
            throw $this->error($where, 'unknown colleague '.json_encode($colleague));
        }

        $ticket = $event['ticket'] ?? null;
        if ($ticket !== null) {
            if (! is_string($ticket) || ! in_array($ticket, $ticketKeys, true)) {
                throw $this->error($where, 'refers to unknown ticket '.(is_string($ticket) ? "'{$ticket}'" : json_encode($ticket)));
            }
        } elseif ($action !== ScenarioAction::Message) {
            throw $this->error($where, "action '{$actionName}' needs a 'ticket'");
        }

        $day = $event['day'] ?? null;
        if ($day !== null && (! is_int($day) || $day < 1 || $day > $duration)) {
            throw $this->error($where, "'day' must be a whole number from 1 to {$duration}");
        }

        $delay = $event['delay_minutes'] ?? 0;
        if (! is_int($delay) || $delay < 0) {
            throw $this->error($where, "'delay_minutes' must be a whole number of 0 or more");
        }

        $payload = array_diff_key($event, array_flip(self::EVENT_COLUMNS));

        $body = $payload['body'] ?? null;
        if ($body !== null && (! is_string($body) || trim($body) === '')) {
            throw $this->error($where, "'body' must be text");
        }

        // A message needs a body; the other actions only send one when it is given.
        // Either way a message needs someone to send it.
        if ($action === ScenarioAction::Message && $body === null) {
            throw $this->error($where, "action 'message' needs a 'body'");
        }
        if ($body !== null && $colleague === null) {
            throw $this->error($where, "a message needs a 'colleague'");
        }

        foreach (['add_criteria'] as $listField) {
            $list = $payload[$listField] ?? [];
            if (! is_array($list) || ! array_is_list($list) || array_filter($list, 'is_string') !== $list) {
                throw $this->error($where, "'{$listField}' must be a list of texts");
            }
        }

        foreach (['description_append', 'title'] as $textField) {
            if (isset($payload[$textField]) && ! is_string($payload[$textField])) {
                throw $this->error($where, "'{$textField}' must be text");
            }
        }

        if ($action === ScenarioAction::OpenPullRequest) {
            if ($colleague === null) {
                throw $this->error($where, "action 'open_pull_request' needs a 'colleague' (the author)");
            }

            $payload = $this->validatePullRequest($payload, (string) $ticket, $ticketTypes[(string) $ticket] ?? '', $where, $directory);
        }

        return [
            'key' => $key,
            'trigger' => $on,
            'ticket_key' => $ticket,
            'day' => $day,
            'delay_minutes' => $delay,
            'action' => $actionName,
            'colleague' => $colleague,
            'payload' => $payload,
        ];
    }

    /**
     * A colleague's pull request. The file contents are read now, so the running app never needs the files.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function validatePullRequest(array $payload, string $ticketKey, string $ticketType, string $where, string $directory): array
    {
        if ($ticketType !== TicketType::Review->value) {
            throw $this->error($where, "action 'open_pull_request' needs a ticket of type 'review', but {$ticketKey} is '{$ticketType}'");
        }

        $branch = $this->requireString($payload, 'branch', $where);
        if (preg_match('/^[A-Za-z0-9._\/-]+$/', $branch) !== 1 || str_contains($branch, '..') || str_starts_with($branch, '/') || str_ends_with($branch, '/')) {
            throw $this->error($where, "'branch' '{$branch}' is not a valid branch name");
        }

        // The same rule the pull request linker uses, so the pull request finds its ticket.
        if (preg_match('/(?<![A-Za-z0-9])'.preg_quote($ticketKey, '/').'(?![0-9])/i', $branch) !== 1) {
            throw $this->error($where, "'branch' must contain the ticket key {$ticketKey}, so the pull request is linked to the ticket");
        }

        $this->requireString($payload, 'title', $where);
        $this->requireString($payload, 'description', $where);

        $commitMessage = $payload['commit_message'] ?? null;
        if ($commitMessage !== null && (! is_string($commitMessage) || trim($commitMessage) === '')) {
            throw $this->error($where, "'commit_message' must be text");
        }

        $files = $payload['files'] ?? null;
        if (! is_array($files) || ! array_is_list($files) || $files === []) {
            throw $this->error($where, "'files' must be a list with at least one file");
        }
        if (count($files) > self::MAX_PULL_REQUEST_FILES) {
            throw $this->error($where, "'files' has more than ".self::MAX_PULL_REQUEST_FILES.' files');
        }

        $root = realpath($directory);
        $stored = [];
        $paths = [];

        foreach ($files as $index => $file) {
            $position = "{$where}, file #".($index + 1);

            if (! is_array($file)) {
                throw $this->error($where, 'file #'.($index + 1).' must be an object');
            }

            $path = $this->requireString($file, 'path', $position);
            if (! $this->isSafePath($path)) {
                throw $this->error($position, "'path' '{$path}' must be a relative path without '..' and not inside .git");
            }
            if (in_array($path, $paths, true)) {
                throw $this->error($position, "duplicate path '{$path}'");
            }
            $paths[] = $path;

            $hasContent = array_key_exists('content', $file);
            $hasFrom = array_key_exists('from', $file);
            if ($hasContent === $hasFrom) {
                throw $this->error($position, "needs either 'content' or 'from', not both");
            }

            if ($hasFrom) {
                $content = $this->readSourceFile($file['from'], $root, $position);
            } else {
                $content = $file['content'];
                if (! is_string($content)) {
                    throw $this->error($position, "'content' must be text");
                }
            }

            if (strlen($content) > self::MAX_FILE_BYTES) {
                throw $this->error($position, 'the file is larger than '.(self::MAX_FILE_BYTES / 1024).' KB');
            }

            $stored[] = ['path' => $path, 'content' => $content];
        }

        $payload['files'] = $stored;

        return $payload;
    }

    private function isSafePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/')) {
            return false;
        }

        foreach (explode('/', $path) as $position => $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }

            if ($position === 0 && strtolower($segment) === '.git') {
                return false;
            }
        }

        return true;
    }

    /**
     * The text of a `from` file. It must stay inside the scenario directory.
     */
    private function readSourceFile(mixed $from, string|false $root, string $where): string
    {
        if (! is_string($from) || trim($from) === '') {
            throw $this->error($where, "'from' must be a file path");
        }

        $full = $root === false ? false : realpath($root.DIRECTORY_SEPARATOR.$from);

        if ($full === false || ! is_file($full)) {
            throw $this->error($where, "'from' file '{$from}' does not exist");
        }

        // realpath resolves '..' and symlinks, so this catches every way out of the directory.
        if (! str_starts_with($full, $root.DIRECTORY_SEPARATOR)) {
            throw $this->error($where, "'from' file '{$from}' must be inside the scenario directory");
        }

        return (string) file_get_contents($full);
    }

    /**
     * @param  array{slug: string, title: string, level: string, summary: string|null, duration_days: int, sequence: int|null, briefing: string|null, colleague_notes: array<string, string>|null, tickets: list<array<string, mixed>>, events: list<array<string, mixed>>}  $data
     */
    private function store(array $data): Scenario
    {
        $scenario = Scenario::updateOrCreate(
            ['slug' => $data['slug']],
            [
                'title' => $data['title'],
                'level' => $data['level'],
                'summary' => $data['summary'],
                'duration_days' => $data['duration_days'],
                'sequence' => $data['sequence'],
                'briefing' => $data['briefing'],
                'colleague_notes' => $data['colleague_notes'],
            ],
        );

        // `sort` follows the order in the file.
        foreach ($data['tickets'] as $sort => $ticket) {
            $scenario->scenarioTickets()->updateOrCreate(
                ['key' => $ticket['key']],
                [...$ticket, 'sort' => $sort],
            );
        }

        foreach ($data['events'] as $sort => $event) {
            $scenario->scenarioEvents()->updateOrCreate(
                ['key' => $event['key']],
                [...$event, 'sort' => $sort],
            );
        }

        return $scenario;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function requireString(array $data, string $field, string $where): string
    {
        $value = $data[$field] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw $this->error($where, "missing or empty '{$field}'");
        }

        return $value;
    }

    private function error(string $where, string $problem): InvalidArgumentException
    {
        return new InvalidArgumentException("Invalid {$where}: {$problem}.");
    }
}
