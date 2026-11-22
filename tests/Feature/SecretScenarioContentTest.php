<?php

namespace Tests\Feature;

use App\Enums\Colleague;
use App\Models\User;
use App\Services\Scenario\ScenarioImporter;
use App\Services\Sprint\SprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Briefing, notes and planted issues are for the AI only. They must never be in a page.
 */
class SecretScenarioContentTest extends TestCase
{
    use RefreshDatabase;

    private const MARKERS = [
        'SECRET-BRIEFING-7f3a',
        'SECRET-CLAIRE-NOTE-2b9c',
        'SECRET-EMILY-NOTE-5d1e',
        'SECRET-REVIEW-NOTE-8c4d',
        'SECRET-PLANTED-ISSUE-1a6b',
    ];

    public function test_secret_scenario_content_never_reaches_the_browser(): void
    {
        $directory = sys_get_temp_dir().'/sprintlab-secret-'.bin2hex(random_bytes(4));
        mkdir($directory);
        file_put_contents($directory.'/secret.json', json_encode([
            'slug' => 'secret-sprint',
            'title' => 'Secret sprint',
            'level' => 'junior',
            'duration_days' => 2,
            'briefing' => self::MARKERS[0],
            'colleague_notes' => ['claire' => self::MARKERS[1], 'emily' => self::MARKERS[2]],
            'tickets' => [
                [
                    'key' => 'WK-1', 'day' => 1, 'type' => 'bug', 'reporter' => 'sarah', 'title' => 'Fix it',
                    'description' => 'Describe.', 'acceptance_criteria' => ['One'],
                    'review_notes' => self::MARKERS[3], 'reviewer' => 'emily',
                ],
                [
                    'key' => 'WK-2', 'day' => 1, 'type' => 'review', 'reporter' => 'josh', 'title' => 'Review Josh',
                    'description' => 'Describe.', 'acceptance_criteria' => [],
                    'planted_issues' => [['id' => 'auth', 'description' => self::MARKERS[4], 'severity' => 'high']],
                ],
            ],
            'events' => [
                ['key' => 'hello', 'on' => 'sprint_started', 'action' => 'message', 'colleague' => 'emily', 'body' => 'Welcome {first_name}.'],
            ],
        ]));

        try {
            $scenario = app(ScenarioImporter::class)->importFile($directory.'/secret.json');
        } finally {
            unlink($directory.'/secret.json');
            rmdir($directory);
        }

        $user = User::factory()->create(['name' => 'Robin Broos']);
        app(SprintService::class)->start($user, $scenario);
        $this->withoutVite()->actingAs($user);

        $urls = [
            route('dashboard'),
            route('board'),
            route('chat'),
            ...array_map(fn (Colleague $colleague): string => route('chat', $colleague->value), Colleague::cases()),
        ];

        foreach ($urls as $url) {
            $content = $this->get($url)->assertOk()->getContent();

            foreach (self::MARKERS as $marker) {
                $this->assertStringNotContainsString($marker, (string) $content, "{$marker} leaked on {$url}");
            }
        }
    }
}
