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
        $scenario = $this->validate($data, $name);

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
     * @return array{slug: string, title: string, level: string, summary: string|null, duration_days: int, tickets: list<array<string, mixed>>, events: list<array<string, mixed>>}
     */
    private function validate(array $data, string $file): array
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

        $summary = $data['summary'] ?? null;
        if ($summary !== null && ! is_string($summary)) {
            throw $this->error($where, "'summary' must be text");
        }

        if (! isset($data['tickets']) || ! is_array($data['tickets']) || ! array_is_list($data['tickets'])) {
            throw $this->error($where, "'tickets' must be a list");
        }

        $events = $data['events'] ?? [];
        if (! is_array($events) || ! array_is_list($events)) {
            throw $this->error($where, "'events' must be a list");
        }

        $tickets = [];
        $ticketKeys = [];

        foreach ($data['tickets'] as $index => $ticket) {
            $tickets[] = $this->validateTicket($ticket, $index, $duration, $ticketKeys, $where);
        }

        $eventList = [];
        $eventKeys = [];

        foreach ($events as $index => $event) {
            $eventList[] = $this->validateEvent($event, $index, $duration, $ticketKeys, $eventKeys, $where);
        }

        return [
            'slug' => $slug,
            'title' => $this->requireString($data, 'title', $where),
            'level' => $level,
            'summary' => $summary,
            'duration_days' => $duration,
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

        return [
            'key' => $key,
            'day' => $day,
            'type' => $type,
            'reporter' => $reporter,
            'title' => $this->requireString($ticket, 'title', $where),
            'description' => $this->requireString($ticket, 'description', $where),
            'acceptance_criteria' => $criteria,
            'released_at_start' => $released,
        ];
    }

    /**
     * @param  list<string>  $ticketKeys
     * @param  list<string>  $seenKeys  filled by this method, to find duplicates
     * @return array<string, mixed>
     */
    private function validateEvent(mixed $event, int $index, int $duration, array $ticketKeys, array &$seenKeys, string $where): array
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
     * @param  array{slug: string, title: string, level: string, summary: string|null, duration_days: int, tickets: list<array<string, mixed>>, events: list<array<string, mixed>>}  $data
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
