<?php

namespace Tests\Feature\GitHub;

use App\Ai\Agents\PullRequestReviewer;
use App\Enums\Colleague;
use App\Enums\ReviewStatus;
use App\Enums\ReviewVerdict;
use App\Jobs\ReviewPullRequest;
use App\Models\Review;
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
            && $prompt->contains("+    12 |     if (\$this->status === 'approved') {"));
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
}
