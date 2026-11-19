<?php

namespace Tests\Feature\Scenario;

use App\Enums\Colleague;
use App\Enums\ScenarioAction;
use App\Enums\ScenarioTrigger;
use App\Enums\TicketType;
use App\Models\Scenario;
use App\Models\ScenarioEvent;
use App\Models\ScenarioTicket;
use App\Services\Scenario\ScenarioImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ScenarioImporterTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/sprintlab-scenarios-'.bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory.'/*') ?: []);
        rmdir($this->directory);

        parent::tearDown();
    }

    /**
     * A small valid scenario, with the given top-level values replaced.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function scenarioData(array $overrides = []): array
    {
        return array_replace([
            'slug' => 'test-sprint',
            'title' => 'Test sprint',
            'level' => 'junior',
            'summary' => 'For tests.',
            'duration_days' => 2,
            'tickets' => [
                [
                    'key' => 'WK-1', 'day' => 1, 'type' => 'bug', 'reporter' => 'sarah',
                    'title' => 'First', 'description' => 'Describe.', 'acceptance_criteria' => ['One'],
                ],
                [
                    'key' => 'WK-2', 'day' => 2, 'type' => 'feature', 'reporter' => 'daniel',
                    'title' => 'Second', 'description' => 'Describe.', 'acceptance_criteria' => [],
                    'released_at_start' => false,
                ],
            ],
            'events' => [
                ['key' => 'welcome', 'on' => 'sprint_started', 'action' => 'message', 'colleague' => 'sarah', 'body' => 'Hi'],
            ],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function write(array $data, string $name = 'scenario.json'): string
    {
        $path = $this->directory.'/'.$name;
        file_put_contents($path, json_encode($data));

        return $path;
    }

    private function assertImportFails(array $data, string $message): void
    {
        try {
            app(ScenarioImporter::class)->importFile($this->write($data, 'broken.json'));
            $this->fail('Expected the import to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('broken.json', $exception->getMessage());
            $this->assertStringContainsString($message, $exception->getMessage());
        }

        $this->assertSame(0, Scenario::count());
    }

    public function test_the_demo_scenario_can_be_imported(): void
    {
        $scenario = app(ScenarioImporter::class)->importFile(resource_path('scenarios/demo-sprint.json'));

        $this->assertSame('demo-sprint', $scenario->slug);
        $this->assertSame(2, $scenario->duration_days);
        $this->assertSame(['WK-1', 'WK-2', 'WK-3'], $scenario->scenarioTickets()->orderBy('sort')->pluck('key')->all());
        $this->assertSame(6, $scenario->scenarioEvents()->count());

        $change = ScenarioEvent::where('key', 'change-wk-2')->sole();
        $this->assertSame(ScenarioTrigger::TicketInReview, $change->trigger);
        $this->assertSame('WK-2', $change->ticket_key);
        $this->assertSame(ScenarioAction::UpdateTicket, $change->action);
        $this->assertSame(Colleague::Daniel, $change->colleague);
        $this->assertArrayHasKey('add_criteria', $change->payload);
        $this->assertArrayNotHasKey('on', $change->payload);
    }

    public function test_tickets_and_events_are_stored_with_their_values(): void
    {
        $scenario = app(ScenarioImporter::class)->importFile($this->write($this->scenarioData()));

        $second = ScenarioTicket::where('scenario_id', $scenario->id)->where('key', 'WK-2')->sole();
        $this->assertSame(TicketType::Feature, $second->type);
        $this->assertSame(Colleague::Daniel, $second->reporter);
        $this->assertFalse($second->released_at_start);
        $this->assertSame(1, $second->sort);
        $this->assertTrue(ScenarioTicket::where('key', 'WK-1')->sole()->released_at_start);
    }

    public function test_importing_again_updates_instead_of_duplicating(): void
    {
        $importer = app(ScenarioImporter::class);

        $importer->importFile($this->write($this->scenarioData()));
        $ticketId = ScenarioTicket::where('key', 'WK-1')->value('id');

        $data = $this->scenarioData(['title' => 'Renamed']);
        $data['tickets'][0]['title'] = 'First, reworded';
        $data['events'][0]['body'] = 'Hello';
        $importer->importFile($this->write($data));

        $this->assertSame(1, Scenario::count());
        $this->assertSame('Renamed', Scenario::sole()->title);
        $this->assertSame(2, ScenarioTicket::count());
        $this->assertSame($ticketId, ScenarioTicket::where('key', 'WK-1')->value('id'));
        $this->assertSame('First, reworded', ScenarioTicket::where('key', 'WK-1')->value('title'));
        $this->assertSame(1, ScenarioEvent::count());
        $this->assertSame('Hello', ScenarioEvent::sole()->payload['body']);
    }

    public function test_rows_that_are_no_longer_in_the_file_are_kept(): void
    {
        $importer = app(ScenarioImporter::class);
        $importer->importFile($this->write($this->scenarioData()));

        $data = $this->scenarioData();
        array_pop($data['tickets']);
        $importer->importFile($this->write($data));

        $this->assertSame(2, ScenarioTicket::count());
    }

    public function test_a_directory_is_imported_file_by_file(): void
    {
        $this->write($this->scenarioData(), 'one.json');
        $this->write($this->scenarioData(['slug' => 'other-sprint']), 'two.json');
        file_put_contents($this->directory.'/notes.txt', 'ignored');

        $count = app(ScenarioImporter::class)->importDirectory($this->directory);

        $this->assertSame(2, $count);
        $this->assertSame(2, Scenario::count());
    }

    public function test_a_file_that_is_not_json_is_refused(): void
    {
        $path = $this->directory.'/broken.json';
        file_put_contents($path, '{ nope');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('broken.json is not valid JSON');

        app(ScenarioImporter::class)->importFile($path);
    }

    public function test_a_missing_file_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('missing.json');

        app(ScenarioImporter::class)->importFile($this->directory.'/missing.json');
    }

    public function test_required_scenario_fields_are_checked(): void
    {
        foreach (['slug', 'title', 'level', 'duration_days', 'tickets'] as $field) {
            $data = $this->scenarioData();
            unset($data[$field]);

            $this->assertImportFails($data, $field === 'tickets' ? "'tickets'" : "'{$field}'");
        }
    }

    public function test_required_ticket_fields_are_checked(): void
    {
        foreach (['key', 'day', 'type', 'reporter', 'title', 'description', 'acceptance_criteria'] as $field) {
            $data = $this->scenarioData();
            unset($data['tickets'][0][$field]);

            $this->assertImportFails($data, $field);
        }
    }

    public function test_required_event_fields_are_checked(): void
    {
        foreach (['key', 'on', 'action'] as $field) {
            $data = $this->scenarioData();
            unset($data['events'][0][$field]);

            $this->assertImportFails($data, "'{$field}'");
        }
    }

    public function test_an_unknown_level_is_refused(): void
    {
        $this->assertImportFails($this->scenarioData(['level' => 'wizard']), "unknown level 'wizard'");
    }

    public function test_an_unknown_ticket_type_is_refused(): void
    {
        $data = $this->scenarioData();
        $data['tickets'][0]['type'] = 'chore';

        $this->assertImportFails($data, "unknown type 'chore'");
    }

    public function test_an_unknown_colleague_is_refused(): void
    {
        $data = $this->scenarioData();
        $data['tickets'][0]['reporter'] = 'bob';
        $this->assertImportFails($data, "unknown reporter 'bob'");

        $data = $this->scenarioData();
        $data['events'][0]['colleague'] = 'bob';
        $this->assertImportFails($data, 'unknown colleague');
    }

    public function test_an_unknown_trigger_or_action_is_refused(): void
    {
        $data = $this->scenarioData();
        $data['events'][0]['on'] = 'lunch_time';
        $this->assertImportFails($data, "unknown trigger 'lunch_time'");

        $data = $this->scenarioData();
        $data['events'][0]['action'] = 'explode';
        $this->assertImportFails($data, "unknown action 'explode'");
    }

    public function test_duplicate_keys_are_refused(): void
    {
        $data = $this->scenarioData();
        $data['tickets'][1]['key'] = 'WK-1';
        $this->assertImportFails($data, 'duplicate ticket key');

        $data = $this->scenarioData();
        $data['events'][] = $data['events'][0];
        $this->assertImportFails($data, 'duplicate event key');
    }

    public function test_a_ticket_day_outside_the_sprint_is_refused(): void
    {
        foreach ([0, 3] as $day) {
            $data = $this->scenarioData();
            $data['tickets'][0]['day'] = $day;

            $this->assertImportFails($data, "'day' must be a whole number from 1 to 2");
        }
    }

    public function test_an_event_with_an_unknown_ticket_is_refused(): void
    {
        $data = $this->scenarioData();
        $data['events'][0]['ticket'] = 'WK-99';

        $this->assertImportFails($data, "unknown ticket 'WK-99'");
    }

    public function test_release_and_update_need_a_ticket(): void
    {
        foreach (['release_ticket', 'update_ticket'] as $action) {
            $data = $this->scenarioData();
            $data['events'][0]['action'] = $action;
            unset($data['events'][0]['body']);

            $this->assertImportFails($data, "action '{$action}' needs a 'ticket'");
        }
    }

    public function test_a_message_needs_a_body_and_a_colleague(): void
    {
        $data = $this->scenarioData();
        unset($data['events'][0]['body']);
        $this->assertImportFails($data, "action 'message' needs a 'body'");

        $data = $this->scenarioData();
        unset($data['events'][0]['colleague']);
        $this->assertImportFails($data, "needs a 'colleague'");
    }

    public function test_an_event_day_outside_the_sprint_is_refused(): void
    {
        $data = $this->scenarioData();
        $data['events'][0]['day'] = 5;

        $this->assertImportFails($data, "'day' must be a whole number from 1 to 2");
    }
}
