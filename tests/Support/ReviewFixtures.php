<?php

namespace Tests\Support;

use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Scenario;
use App\Models\ScenarioTicket;
use App\Models\Sprint;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Facades\Http;

/**
 * Helpers for tests about grading the user's review of a colleague's pull
 * request. The test class must also use GitHubFixtures.
 */
trait ReviewFixtures
{
    /**
     * @return list<array{id: string, file?: string, description: string, severity: string}>
     */
    protected function plantedIssues(): array
    {
        return [
            ['id' => 'authorization', 'file' => 'app/Http/Controllers/RepairController.php', 'description' => 'Any logged-in user can update any repair.', 'severity' => 'high'],
            ['id' => 'validation', 'file' => 'app/Http/Controllers/RepairController.php', 'description' => 'The status field is not validated.', 'severity' => 'medium'],
            ['id' => 'naming', 'description' => 'Unclear variable names.', 'severity' => 'low'],
        ];
    }

    /**
     * A review ticket of the user (GitHub id 4242) with the colleague's pull
     * request linked to it.
     *
     * @param  list<array{id: string, file?: string, description: string, severity: string}>|null  $issues
     */
    protected function createReviewTicket(?array $issues = null): Ticket
    {
        $user = User::factory()->create(['github_id' => 4242, 'name' => 'Robin Broos']);
        $scenario = Scenario::factory()->create();
        $scenarioTicket = ScenarioTicket::factory()->for($scenario)->withPlantedIssues($issues ?? $this->plantedIssues())->create();
        $sprint = Sprint::factory()->for($user)->for($scenario)->create();
        $pullRequest = $this->createPullRequest($user);

        return Ticket::factory()->for($sprint)->for($scenarioTicket)->released()->status(TicketStatus::InProgress)->create([
            'type' => TicketType::Review,
            'pull_request_id' => $pullRequest->id,
        ]);
    }

    /**
     * Fake GitHub for one submitted review: token, review, its inline comments
     * and the files of the pull request.
     *
     * @param  list<array{path: string, line: int|null, body: string}>  $comments
     */
    protected function fakeGitHubReview(int $reviewId = 777, string $state = 'CHANGES_REQUESTED', ?string $body = 'The controller lets anyone update any repair.', array $comments = [], int $reviewStatus = 200): void
    {
        Http::fake([
            'api.github.com/app/installations/*/access_tokens' => Http::response([
                'token' => 'installation-token',
                'expires_at' => now()->addHour()->toIso8601String(),
            ], 201),
            "api.github.com/repos/robin/workkit/pulls/12/reviews/{$reviewId}/comments*" => Http::response($comments),
            "api.github.com/repos/robin/workkit/pulls/12/reviews/{$reviewId}" => Http::response([
                'id' => $reviewId,
                'state' => $state,
                'body' => $body,
                'user' => ['login' => 'robin'],
            ], $reviewStatus),
            'api.github.com/repos/robin/workkit/pulls/12/files*' => Http::response([[
                'filename' => 'app/Http/Controllers/RepairController.php',
                'status' => 'modified',
                'additions' => 2,
                'deletions' => 0,
                'patch' => "@@ -10,3 +10,5 @@\n public function update(): void\n {\n+    \$repair->update(request()->all());\n+    return;",
            ]]),
        ]);
    }

    /**
     * What the model answers: by default every problem found, top marks.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function assessorAnswer(array $overrides = []): array
    {
        return array_replace([
            'issues' => [
                ['id' => 'authorization', 'found' => true, 'evidence' => 'The controller lets anyone update any repair.'],
                ['id' => 'validation', 'found' => true, 'evidence' => 'Validate the status.'],
                ['id' => 'naming', 'found' => true, 'evidence' => 'Rename $x.'],
            ],
            'false_positives' => [],
            'clarity' => 5,
            'tone' => 5,
            'feedback' => 'Good catch on the controller. Have another look at the naming.',
        ], $overrides);
    }

    /**
     * A webhook payload of a submitted review.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function reviewPayload(string $action = 'submitted', array $overrides = []): array
    {
        return array_replace_recursive([
            'action' => $action,
            'installation' => ['id' => 9001],
            'repository' => ['id' => 7001, 'full_name' => 'robin/workkit', 'private' => true, 'owner' => ['login' => 'robin', 'id' => 4242]],
            'pull_request' => ['number' => 12],
            'review' => ['id' => 777, 'state' => 'changes_requested', 'user' => ['login' => 'robin', 'type' => 'User']],
            'sender' => ['id' => 4242, 'login' => 'robin', 'type' => 'User'],
        ], $overrides);
    }
}
