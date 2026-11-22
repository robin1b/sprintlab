<?php

namespace Tests\Feature\GitHub;

use App\Ai\Agents\PullRequestReviewer;
use App\Enums\AiFeature;
use App\Enums\Colleague;
use App\Enums\ReviewStatus;
use App\Enums\ReviewVerdict;
use App\Enums\TicketType;
use App\Jobs\ReviewPullRequest;
use App\Models\AiCall;
use App\Models\PullRequest;
use App\Models\Review;
use App\Models\Scenario;
use App\Models\ScenarioTicket;
use App\Models\Sprint;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Review\PullRequestReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\Support\GitHubFixtures;
use Tests\TestCase;

class PullRequestReviewTest extends TestCase
{
    use GitHubFixtures, RefreshDatabase;

    private string $sha;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sha = str_repeat('a', 40);
        $this->configureGitHubApp();
    }

    /**
     * Fake GitHub: the token call, the changed files and the review call.
     *
     * @param  list<array<string, mixed>>|null  $files
     */
    private function fakeGitHub(?array $files = null, int $reviewStatus = 200): void
    {
        Http::fake([
            'api.github.com/app/installations/*/access_tokens' => Http::response([
                'token' => 'installation-token',
                'expires_at' => now()->addHour()->toIso8601String(),
            ], 201),
            'api.github.com/repos/robin/workkit/pulls/12/files*' => Http::response($files ?? [[
                'filename' => 'app/Models/LeaseRequest.php',
                'status' => 'modified',
                'additions' => 3,
                'deletions' => 1,
                'patch' => "@@ -10,3 +10,5 @@\n public function approve(): void\n {\n-    \$this->status = 'approved';\n+    if (\$this->status === 'approved') {\n+        return;\n+    }",
            ]]),
            'api.github.com/repos/robin/workkit/pulls/12/reviews' => Http::response(['id' => 555], $reviewStatus),
        ]);
    }

    /**
     * @param  list<array{path: string, line: int, body: string}>  $comments
     */
    private function fakeReviewer(string $verdict = 'request_changes', array $comments = []): void
    {
        PullRequestReviewer::fake([[
            'verdict' => $verdict,
            'summary' => 'The early return works, but there is no test for approving twice.',
            'comments' => $comments,
        ]]);
    }

    public function test_the_job_posts_the_review_of_the_team_lead_on_github(): void
    {
        $pullRequest = $this->createPullRequest();
        $this->fakeGitHub();
        $this->fakeReviewer(comments: [
            ['path' => 'app/Models/LeaseRequest.php', 'line' => 12, 'body' => 'Good guard. Please cover it with a test.'],
        ]);

        ReviewPullRequest::dispatch($pullRequest->id, $this->sha);

        $review = Review::sole();

        $this->assertSame(ReviewStatus::Posted, $review->status);
        $this->assertSame(ReviewVerdict::RequestChanges, $review->verdict);
        $this->assertSame(Colleague::Sarah, $review->colleague);
        $this->assertSame(555, $review->github_review_id);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/pulls/12/reviews')
            && $request['event'] === 'REQUEST_CHANGES'
            && $request['commit_id'] === $this->sha
            && str_starts_with($request['body'], '**Sarah** (Team lead)')
            && str_contains($request['body'], 'no test for approving twice')
            && $request['comments'] === [[
                'path' => 'app/Models/LeaseRequest.php',
                'line' => 12,
                'side' => 'RIGHT',
                'body' => 'Good guard. Please cover it with a test.',
            ]]);

        // The model saw the title, the description and the numbered diff.
        PullRequestReviewer::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('Fix double approval of lease requests')
            && $prompt->contains('Approving twice no longer creates two contracts.')
            && $prompt->contains('File: app/Models/LeaseRequest.php')
            && $prompt->contains("+    12 |     if (\$this->status === 'approved') {")
            // The privacy page promises that the GitHub username stays here.
            && ! $prompt->contains('Author:'));
    }

    public function test_a_comment_on_a_line_outside_the_diff_moves_into_the_review_text(): void
    {
        $pullRequest = $this->createPullRequest();
        $this->fakeGitHub();
        $this->fakeReviewer(comments: [
            ['path' => 'app/Models/LeaseRequest.php', 'line' => 250, 'body' => 'This method is getting long.'],
            ['path' => 'routes/web.php', 'line' => 3, 'body' => 'Is this route protected?'],
        ]);

        app(PullRequestReviewService::class)->review($pullRequest, $this->sha);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/pulls/12/reviews')
            && $request['comments'] === []
            && str_contains($request['body'], '- `app/Models/LeaseRequest.php`: This method is getting long.')
            && str_contains($request['body'], '- `routes/web.php`: Is this route protected?'));
    }

    public function test_an_approval_is_submitted_as_an_approval(): void
    {
        $pullRequest = $this->createPullRequest();
        $this->fakeGitHub();
        $this->fakeReviewer('approve');

        app(PullRequestReviewService::class)->review($pullRequest, $this->sha);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/pulls/12/reviews') && $request['event'] === 'APPROVE');
    }

    public function test_the_same_commit_is_never_reviewed_twice(): void
    {
        $pullRequest = $this->createPullRequest();
        $this->fakeGitHub();
        $this->fakeReviewer();

        $service = app(PullRequestReviewService::class);
        $service->review($pullRequest, $this->sha);
        $service->review($pullRequest, $this->sha);

        $this->assertSame(1, Review::count());
        PullRequestReviewer::assertPromptedTimes(1);
        Http::assertSentCount(3); // token, files, review: all once
    }

    public function test_an_outdated_commit_is_not_reviewed(): void
    {
        $pullRequest = $this->createPullRequest();
        $this->fakeGitHub();
        $this->fakeReviewer();

        $review = app(PullRequestReviewService::class)->review($pullRequest, str_repeat('b', 40));

        $this->assertNull($review);
        PullRequestReviewer::assertNeverPrompted();
        Http::assertNothingSent();
    }

    public function test_nothing_happens_while_ai_is_switched_off(): void
    {
        config(['sprintlab.ai.enabled' => false]);

        $pullRequest = $this->createPullRequest();
        $this->fakeGitHub();
        $this->fakeReviewer();

        $this->assertNull(app(PullRequestReviewService::class)->review($pullRequest, $this->sha));
        $this->assertSame(0, Review::count());
        Http::assertNothingSent();
    }

    public function test_the_daily_limit_stops_further_reviews(): void
    {
        config(['sprintlab.ai.reviews_per_installation_per_day' => 1]);

        $pullRequest = $this->createPullRequest();
        $this->fakeGitHub();
        $this->fakeReviewer();

        $service = app(PullRequestReviewService::class);
        $service->review($pullRequest, $this->sha);

        // A second commit on the same day.
        $pullRequest->update(['head_sha' => str_repeat('b', 40)]);
        $second = $service->review($pullRequest, str_repeat('b', 40));

        $this->assertSame(ReviewStatus::Skipped, $second?->status);
        $this->assertSame('Daily review limit reached.', $second?->error);
        PullRequestReviewer::assertPromptedTimes(1);
    }

    public function test_a_pull_request_with_only_generated_files_is_skipped(): void
    {
        $pullRequest = $this->createPullRequest();
        $this->fakeGitHub([
            ['filename' => 'composer.lock', 'status' => 'modified', 'additions' => 40, 'deletions' => 2, 'patch' => "@@ -1,1 +1,1 @@\n-a\n+b"],
            ['filename' => 'public/build/app.js', 'status' => 'added', 'additions' => 1, 'deletions' => 0, 'patch' => "@@ -0,0 +1,1 @@\n+x"],
            ['filename' => 'public/logo.png', 'status' => 'added', 'additions' => 0, 'deletions' => 0],
        ]);
        $this->fakeReviewer();

        $review = app(PullRequestReviewService::class)->review($pullRequest, $this->sha);

        $this->assertSame(ReviewStatus::Skipped, $review?->status);
        PullRequestReviewer::assertNeverPrompted();
    }

    public function test_when_github_refuses_the_review_the_retry_reuses_the_answer_of_the_model(): void
    {
        $pullRequest = $this->createPullRequest();
        $this->fakeGitHub(reviewStatus: 500);
        $this->fakeReviewer();

        $service = app(PullRequestReviewService::class);

        try {
            $service->review($pullRequest, $this->sha);
            $this->fail('The failed GitHub call should have thrown.');
        } catch (RequestException) {
            $this->assertSame(ReviewStatus::Failed, Review::sole()->status);
        }

        // GitHub works again: the retry posts without asking the model again.
        Http::swap(new Factory);
        $this->fakeGitHub();

        $service->review($pullRequest, $this->sha);

        $this->assertSame(ReviewStatus::Posted, Review::sole()->status);
        PullRequestReviewer::assertPromptedTimes(1);
    }

    public function test_a_very_large_diff_is_cut_off_and_the_model_is_told(): void
    {
        config(['sprintlab.ai.max_diff_characters' => 200]);

        $pullRequest = $this->createPullRequest();
        $this->fakeGitHub([
            ['filename' => 'app/Small.php', 'status' => 'modified', 'additions' => 1, 'deletions' => 0, 'patch' => "@@ -1,1 +1,2 @@\n a\n+b"],
            ['filename' => 'app/Huge.php', 'status' => 'added', 'additions' => 300, 'deletions' => 0, 'patch' => "@@ -0,0 +1,300 @@\n".str_repeat("+line\n", 300)],
        ]);
        $this->fakeReviewer();

        app(PullRequestReviewService::class)->review($pullRequest, $this->sha);

        PullRequestReviewer::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('File: app/Small.php')
            && ! $prompt->contains('File: app/Huge.php')
            && $prompt->contains('The diff was cut off'));
    }

    public function test_the_review_is_counted_as_an_ai_call_of_the_installation_user(): void
    {
        $user = User::factory()->create();
        $pullRequest = $this->createPullRequest($user);
        $this->fakeGitHub();
        $this->fakeReviewer();

        ReviewPullRequest::dispatch($pullRequest->id, $this->sha);

        $review = Review::sole();
        $call = AiCall::sole();

        $this->assertSame(AiFeature::Review, $call->feature);
        $this->assertSame($user->id, $call->user_id);
        $this->assertNull($call->sprint_id);
        $this->assertNotSame('', $call->model);
        // The fake decides the model and the (zero) tokens: the call must match what the review stored.
        $this->assertSame($review->model, $call->model);
        $this->assertSame($review->input_tokens, $call->input_tokens);
        $this->assertSame($review->output_tokens, $call->output_tokens);
    }

    public function test_the_review_is_skipped_without_calling_the_model_when_the_budget_is_reached(): void
    {
        config(['sprintlab.ai.monthly_budget_usd' => 1]);
        AiCall::factory()->create(['cost_micros' => 1_000_000]);
        $pullRequest = $this->createPullRequest();
        $this->fakeGitHub();
        $this->fakeReviewer();

        ReviewPullRequest::dispatch($pullRequest->id, $this->sha);

        $review = Review::sole();
        $this->assertSame(ReviewStatus::Skipped, $review->status);
        $this->assertSame('Monthly AI budget reached.', $review->error);
        PullRequestReviewer::assertNeverPrompted();
        $this->assertSame(1, AiCall::count());
        Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/pulls/12/reviews'));
    }

    /**
     * Link the pull request to a ticket of a sprint.
     *
     * @param  array<string, mixed>  $ticket
     * @param  array<string, mixed>  $scenarioTicket
     * @param  array<string, mixed>  $scenario
     */
    private function linkTicket(PullRequest $pullRequest, array $ticket = [], array $scenarioTicket = [], array $scenario = []): Ticket
    {
        $scenarioModel = Scenario::factory()->create(['level' => 'medior', ...$scenario]);
        $source = ScenarioTicket::factory()->create(['scenario_id' => $scenarioModel->id, ...$scenarioTicket]);
        $sprint = Sprint::factory()->create(['scenario_id' => $scenarioModel->id]);

        return Ticket::factory()->create([
            'sprint_id' => $sprint->id,
            'scenario_ticket_id' => $source->id,
            'key' => 'WK-7',
            'title' => 'Block double approval',
            'description' => 'Approving twice creates two contracts.',
            'acceptance_criteria' => ['Approving twice is refused', 'A test covers it', 'Added later: log the attempt'],
            'reporter' => Colleague::Daniel,
            'pull_request_id' => $pullRequest->id,
            ...$ticket,
        ]);
    }

    public function test_the_prompt_holds_the_ticket_its_current_criteria_and_the_review_notes(): void
    {
        $pullRequest = $this->createPullRequest();
        $this->linkTicket($pullRequest, scenarioTicket: ['review_notes' => 'Typical mistake: forgetting the locked row.']);
        $this->fakeGitHub();
        $this->fakeReviewer();

        app(PullRequestReviewService::class)->review($pullRequest, $this->sha);

        PullRequestReviewer::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('Ticket WK-7: Block double approval')
            && $prompt->contains('Reported by Daniel.')
            && $prompt->contains('Approving twice creates two contracts.')
            && $prompt->contains('- Approving twice is refused')
            && $prompt->contains('- A test covers it')
            && $prompt->contains('- Added later: log the attempt')
            && $prompt->contains('Notes from the team for you as the reviewer.')
            && $prompt->contains('Typical mistake: forgetting the locked row.')
            && $prompt->contains('Pull request #12'));
    }

    public function test_without_review_notes_the_notes_paragraph_is_left_out(): void
    {
        $pullRequest = $this->createPullRequest();
        $this->linkTicket($pullRequest, ['acceptance_criteria' => []]);
        $this->fakeGitHub();
        $this->fakeReviewer();

        app(PullRequestReviewService::class)->review($pullRequest, $this->sha);

        PullRequestReviewer::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('Ticket WK-7')
            && $prompt->contains('- (none)')
            && ! $prompt->contains('Notes from the team'));
    }

    public function test_without_a_linked_ticket_the_prompt_says_so(): void
    {
        $pullRequest = $this->createPullRequest();
        $this->fakeGitHub();
        $this->fakeReviewer();

        app(PullRequestReviewService::class)->review($pullRequest, $this->sha);

        PullRequestReviewer::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('This pull request is not linked to a ticket on the board.')
            && ! $prompt->contains('Acceptance criteria'));
    }

    public function test_the_reviewer_named_by_the_ticket_writes_the_review(): void
    {
        $pullRequest = $this->createPullRequest();
        $this->linkTicket($pullRequest, scenarioTicket: ['reviewer' => Colleague::Emily]);
        $this->fakeGitHub();
        $this->fakeReviewer();

        app(PullRequestReviewService::class)->review($pullRequest, $this->sha);

        $this->assertSame(Colleague::Emily, Review::sole()->colleague);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/pulls/12/reviews')
            && str_starts_with($request['body'], '**Emily** (Developer)'));
    }

    public function test_a_given_colleague_wins_over_the_reviewer_of_the_ticket(): void
    {
        $pullRequest = $this->createPullRequest();
        $this->linkTicket($pullRequest, scenarioTicket: ['reviewer' => Colleague::Emily]);
        $this->fakeGitHub();
        $this->fakeReviewer();

        app(PullRequestReviewService::class)->review($pullRequest, $this->sha, Colleague::Josh);

        $this->assertSame(Colleague::Josh, Review::sole()->colleague);
    }

    public function test_a_pull_request_of_a_review_ticket_is_not_reviewed(): void
    {
        $pullRequest = $this->createPullRequest();
        $this->linkTicket($pullRequest, ['type' => TicketType::Review]);
        $this->fakeGitHub();
        $this->fakeReviewer();

        $this->assertNull(app(PullRequestReviewService::class)->review($pullRequest, $this->sha));

        $this->assertSame(0, Review::count());
        PullRequestReviewer::assertNeverPrompted();
        Http::assertNothingSent();
    }

    public function test_the_level_and_briefing_of_the_scenario_reach_the_reviewer(): void
    {
        $pullRequest = $this->createPullRequest();
        $this->linkTicket($pullRequest, scenario: ['briefing' => 'BRIEFING-IN-REVIEW']);
        $this->fakeGitHub();
        $this->fakeReviewer();

        app(PullRequestReviewService::class)->review($pullRequest, $this->sha);

        PullRequestReviewer::assertPrompted(fn (AgentPrompt $prompt) => str_contains($prompt->agent->instructions(), 'BRIEFING-IN-REVIEW')
            && str_contains($prompt->agent->instructions(), 'written by a medior developer'));
    }

    public function test_the_briefing_is_part_of_the_instructions_of_the_reviewer(): void
    {
        $instructions = (new PullRequestReviewer(Colleague::Sarah, 'junior', 'BRIEFING TEXT'))->instructions();

        $this->assertStringContainsString("\n\nWhat you know about the codebase and how the team works:\nBRIEFING TEXT", $instructions);
        $this->assertStringContainsString('Go through its acceptance criteria one by one', $instructions);
        $this->assertStringContainsString('Notes from the team in the prompt are private guidance.', $instructions);
        $this->assertStringNotContainsString('What you know about', (new PullRequestReviewer(Colleague::Sarah))->instructions());
    }

    public function test_a_second_review_knows_what_the_first_one_said(): void
    {
        $pullRequest = $this->createPullRequest();

        Review::create([
            'pull_request_id' => $pullRequest->id,
            'colleague' => Colleague::Sarah,
            'head_sha' => str_repeat('0', 40),
            'status' => ReviewStatus::Posted,
            'verdict' => ReviewVerdict::RequestChanges,
            'summary' => 'The guard is right, but nothing tests the second approval.',
            'comments' => [['path' => 'app/Models/LeaseRequest.php', 'line' => 12, 'body' => 'Please cover this with a test.']],
        ]);

        $this->fakeGitHub();
        $this->fakeReviewer('approve');

        (new ReviewPullRequest($pullRequest->id, $pullRequest->head_sha))->handle(app(PullRequestReviewService::class));

        PullRequestReviewer::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('reviewed before, on an older commit, by Sarah')
            && $prompt->contains('The verdict was "request changes"')
            && $prompt->contains('nothing tests the second approval')
            && $prompt->contains('- app/Models/LeaseRequest.php: Please cover this with a test.')
            && $prompt->contains('do not repeat a remark that was fixed'));
    }

    public function test_a_first_review_has_no_earlier_review_in_its_prompt(): void
    {
        $pullRequest = $this->createPullRequest();
        $this->fakeGitHub();
        $this->fakeReviewer();

        (new ReviewPullRequest($pullRequest->id, $pullRequest->head_sha))->handle(app(PullRequestReviewService::class));

        PullRequestReviewer::assertPrompted(fn (AgentPrompt $prompt) => ! $prompt->contains('reviewed before'));
    }
}
