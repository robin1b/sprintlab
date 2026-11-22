<?php

namespace Tests\Feature\EndToEnd;

use App\Ai\Agents\ColleagueChat;
use App\Ai\Agents\EvaluationInterviewer;
use App\Ai\Agents\EvaluationWriter;
use App\Ai\Agents\PullRequestReviewer;
use App\Enums\EvaluationStatus;
use App\Enums\PullRequestState;
use App\Enums\ReviewStatus;
use App\Enums\ReviewVerdict;
use App\Enums\SprintStatus;
use App\Enums\TicketStatus;
use App\Models\GitHubInstallation;
use App\Models\Message;
use App\Models\Repository;
use App\Models\Review;
use App\Models\Sprint;
use App\Models\SprintEvaluation;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\GitHubFixtures;
use Tests\Support\SprintFixtures;
use Tests\TestCase;

/**
 * Plays the whole demo sprint the way a user does: through the pages, the
 * GitHub webhooks and the artisan scheduler. Only GitHub's HTTP API and the AI
 * agents are faked. Queued jobs run synchronously in tests.
 */
class DemoSprintTest extends TestCase
{
    use GitHubFixtures, RefreshDatabase, SprintFixtures;

    private User $user;

    private int $delivery = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->configureGitHubApp();
        config(['sprintlab.ai.enabled' => true]);
    }

    public function test_a_user_plays_the_demo_sprint_from_start_to_evaluation(): void
    {
        $this->fakeGitHubApi();
        PullRequestReviewer::fake([
            ['verdict' => 'approve', 'summary' => 'Welcome to the team! Your Team section is spot on.', 'comments' => []],
            ['verdict' => 'request_changes', 'summary' => 'The guard works, but there is no test for approving twice.', 'comments' => []],
            ['verdict' => 'comment', 'summary' => 'The filter looks fine. Is the for-sale option covered?', 'comments' => []],
            ['verdict' => 'approve', 'summary' => 'Clear and small. Nice work.', 'comments' => []],
        ]);
        ColleagueChat::fake(['Have a look at the approve method of the lease request first.']);
        EvaluationInterviewer::fake([[
            'communication' => 4,
            'communication_evidence' => 'You asked Emily where to start.',
            'questions' => [
                'Why did you guard the approval in the model?',
                'How did you handle the change on WK-2?',
                'What would you do differently next time?',
            ],
        ]]);
        EvaluationWriter::fake([[
            'answers' => [
                ['index' => 1, 'understanding' => 5, 'note' => 'Accurate.'],
                ['index' => 2, 'understanding' => 4, 'note' => 'Good.'],
                ['index' => 3, 'understanding' => 3, 'note' => 'Roughly right.'],
            ],
            'strengths' => 'You kept every fix small.',
            'growth' => 'Write the test first.',
            'advice' => 'Add a test before you open the pull request.',
        ]]);

        // 1. Signing in, a connected repository, the dashboard and the start of the demo sprint.
        $this->user = User::factory()->create(['name' => 'Robin Broos', 'github_id' => 4242]);
        $repository = $this->connectRepository();
        $demo = $this->demoScenario();
        $this->actingAs($this->user);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('overview', null)
                ->where('completed', null)
                ->where('setup.ready', true)
                ->where('setup.repository.full_name', 'robin/workkit')
                ->where('scenarios', fn ($scenarios) => collect($scenarios)->contains('id', $demo->id)));

        $this->post(route('sprints.store'), ['scenario_id' => $demo->id])->assertRedirect(route('board'));

        $sprint = Sprint::sole();
        $this->assertSame(SprintStatus::Active, $sprint->status);
        $this->assertSame(1, $sprint->current_day);
        $this->assertSame($repository->id, $sprint->repository_id);

        // 2. The board holds the tickets of day one, the chat the welcome messages.
        $this->get(route('board'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Board')
                ->has('tickets', 3)
                ->where('tickets.0.key', 'WK-0')
                ->where('tickets.0.guided', true)
                ->where('tickets.0.suggested_branch', 'wk-0-add-yourself-team-in-readme')
                ->where('tickets.1.key', 'WK-1')
                ->where('tickets.1.guided', false)
                ->where('tickets.1.status', 'todo')
                ->where('tickets.1.type', 'bug')
                ->where('tickets.2.key', 'WK-2')
                ->where('tickets.2.status', 'todo')
                ->where('tickets.1.suggested_branch', 'wk-1-lease-request-approved-twice')
                ->where('repository.full_name', 'robin/workkit')
                ->where('repository.url', 'https://github.com/robin/workkit'));

        $this->get(route('chat'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Chat')
                ->where('selected', 'daniel')
                ->where('messages.0.body', fn (string $body) => str_starts_with($body, 'Hey Robin, Daniel here'))
                ->where('can_send', true));

        $this->get(route('chat', 'sarah'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('messages', 1)
                ->where('messages.0.body', fn (string $body) => str_starts_with($body, 'Hi Robin, welcome to the Workkit team!')));

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('overview.todays_tickets', fn ($tickets) => collect($tickets)->pluck('key')->all() === ['WK-0', 'WK-1', 'WK-2'])
                ->where('overview.ticket_counts.0.count', 3));

        // 2b. The guided warm-up ticket WK-0: branch, pull request with the key in its title, review, merge.
        $this->pushBranch('wk-0-add-yourself-team-in-readme', 'delivery-wk-0-branch')->assertAccepted();
        $this->assertTicketStatus('WK-0', TicketStatus::InProgress);

        $this->pullRequest('opened', 11, 'wk-0-add-yourself-team-in-readme', 'WK-0: Add myself to the team')->assertAccepted();
        $this->assertTicketStatus('WK-0', TicketStatus::InReview);
        $this->assertSame(ReviewVerdict::Approve, Review::sole()->verdict);

        $this->get(route('board'))->assertInertia(fn (Assert $page) => $page
            ->where('tickets.0.pull_request.number', 11)
            ->where('tickets.0.pull_request.review', ['status' => 'posted', 'verdict' => 'approve']));

        $this->pullRequest('closed', 11, 'wk-0-add-yourself-team-in-readme', 'WK-0: Add myself to the team', merged: true)->assertAccepted();
        $this->assertTicketStatus('WK-0', TicketStatus::Done);
        $this->assertSame(1, $sprint->fresh()->current_day);

        // 3. Pushing a branch for the next ticket starts it.
        $this->pushBranch('wk-1-lease-request-approved-twice')->assertAccepted();

        $this->assertTicketStatus('WK-1', TicketStatus::InProgress);
        $this->assertTicketStatus('WK-2', TicketStatus::Todo);

        // 4. Opening the pull request puts the ticket in review and Sarah reviews it on GitHub.
        $this->pullRequest('opened', 12, 'wk-1-lease-request-approved-twice', 'WK-1: Fix double approval')->assertAccepted();

        $this->assertTicketStatus('WK-1', TicketStatus::InReview);

        $review = Review::where('pull_request_id', $this->ticket('WK-1')->pull_request_id)->sole();
        $this->assertSame(ReviewStatus::Posted, $review->status);
        $this->assertSame(ReviewVerdict::RequestChanges, $review->verdict);
        $this->assertSame(555, $review->github_review_id);
        $this->assertSame(str_repeat('a', 40), $review->head_sha);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/repos/robin/workkit/pulls/12/reviews')
            && $request['event'] === 'REQUEST_CHANGES'
            && str_contains($request['body'], 'no test for approving twice'));

        $this->get(route('board'))->assertInertia(fn (Assert $page) => $page
            ->where('tickets.1.status', 'in_review')
            ->where('tickets.1.pull_request.number', 12)
            ->where('tickets.1.pull_request.url', 'https://github.com/robin/workkit/pull/12')
            ->where('tickets.1.pull_request.review', ['status' => 'posted', 'verdict' => 'request_changes']));

        // 5. Merging finishes the ticket. WK-2 is still open, so day two does not start yet.
        $this->pullRequest('closed', 12, 'wk-1-lease-request-approved-twice', 'WK-1: Fix double approval', merged: true)->assertAccepted();

        $this->assertTicketStatus('WK-1', TicketStatus::Done);
        $this->assertSame(PullRequestState::Merged, Ticket::where('key', 'WK-1')->sole()->pullRequest->state);
        $this->assertSame(1, $sprint->fresh()->current_day);
        $this->assertFalse(Ticket::where('key', 'WK-3')->sole()->isReleased());

        // The scenario has no timed events: after a day the tick has nothing to fire.
        $this->travel(1)->days();
        $this->artisan('sprintlab:tick')->expectsOutput('Fired 0 scenario events.')->assertSuccessful();
        $this->assertSame(1, $sprint->fresh()->current_day);
        $this->travelBack();

        // 6. The user asks Emily a question and she answers.
        $this->post(route('chat.messages.store', 'emily'), ['body' => 'Where do I start with WK-2?'])->assertRedirect();

        $this->get(route('chat', 'emily'))->assertInertia(fn (Assert $page) => $page
            ->has('messages', 2)
            ->where('messages.0.from_user', true)
            ->where('messages.1.from_user', false)
            ->where('messages.1.body', 'Have a look at the approve method of the lease request first.')
            ->where('awaiting_reply', false));
        ColleagueChat::assertPrompted(fn ($prompt) => $prompt->contains('Where do I start with WK-2?'));

        // 7a. WK-2: the branch, the pull request, and Daniel changes the ticket the moment it is in review.
        $this->pushBranch('wk-2-monthly-budget-filter', 'delivery-wk-2-branch')->assertAccepted();
        $this->assertTicketStatus('WK-2', TicketStatus::InProgress);
        $this->assertCount(4, $this->ticket('WK-2')->acceptance_criteria);

        $this->pullRequest('opened', 13, 'wk-2-monthly-budget-filter', 'WK-2: Filter by monthly budget')->assertAccepted();

        $this->assertTicketStatus('WK-2', TicketStatus::InReview);
        $this->assertCount(5, $this->ticket('WK-2')->acceptance_criteria);
        $this->assertSame('The filter can also be limited to devices that are for sale', $this->ticket('WK-2')->acceptance_criteria[4]);
        $this->assertSame(ReviewVerdict::Comment, Review::where('github_review_id', 555)->orderByDesc('id')->first()->verdict);

        $this->get(route('board'))->assertInertia(fn (Assert $page) => $page
            ->where('tickets.2.acceptance_criteria.4', 'The filter can also be limited to devices that are for sale'));
        $this->get(route('chat', 'daniel'))->assertInertia(fn (Assert $page) => $page
            ->where('messages', fn ($messages) => collect($messages)->contains(fn (array $message) => str_starts_with($message['body'], 'Sorry Robin, a small change on WK-2')
                && $message['ticket_key'] === 'WK-2')));

        // Merging the last ticket of day one starts day two: WK-3 is released and Daniel says good morning.
        $this->pullRequest('closed', 13, 'wk-2-monthly-budget-filter', 'WK-2: Filter by monthly budget', merged: true)->assertAccepted();

        $this->assertTicketStatus('WK-2', TicketStatus::Done);
        $this->assertSame(2, $sprint->fresh()->current_day);
        $this->assertTrue($this->ticket('WK-3')->isReleased());
        $this->assertTicketStatus('WK-3', TicketStatus::Todo);

        $this->get(route('board'))->assertInertia(fn (Assert $page) => $page
            ->has('tickets', 4)
            ->where('tickets.3.key', 'WK-3')
            ->where('tickets.3.day', 2));
        $this->get(route('chat', 'daniel'))->assertInertia(fn (Assert $page) => $page
            ->where('messages', fn ($messages) => collect($messages)->contains(fn (array $message) => str_starts_with($message['body'], 'Good morning Robin! Day two.'))));
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('overview.todays_tickets', fn ($tickets) => collect($tickets)->pluck('key')->all() === ['WK-3'])
            ->where('overview.ticket_counts.3.count', 3));

        // 7b. WK-3, the last ticket. The demo has no pull request from a colleague to review.
        $this->pushBranch('wk-3-repair-status', 'delivery-wk-3-branch')->assertAccepted();
        $this->assertTicketStatus('WK-3', TicketStatus::InProgress);

        $this->pullRequest('opened', 14, 'wk-3-repair-status', 'WK-3: Show the repair status')->assertAccepted();
        $this->assertTicketStatus('WK-3', TicketStatus::InReview);
        $this->assertSame(4, Review::where('status', ReviewStatus::Posted)->count());

        $this->assertSame(SprintStatus::Active, $sprint->fresh()->status);
        $this->assertSame(0, SprintEvaluation::count());

        // 8. Merging the last ticket completes the sprint and prepares the evaluation.
        $this->pullRequest('closed', 14, 'wk-3-repair-status', 'WK-3: Show the repair status', merged: true)->assertAccepted();

        $this->assertTicketStatus('WK-3', TicketStatus::Done);
        $sprint->refresh();
        $this->assertSame(SprintStatus::Completed, $sprint->status);
        $this->assertNotNull($sprint->completed_at);
        $this->assertSame(0, Ticket::where('status', '!=', TicketStatus::Done)->count());

        $closing = Message::where('colleague', 'sarah')->where('from_user', false)->pluck('body');
        $this->assertTrue($closing->contains('WK-3 is merged, nice work. Employees will phone HR a lot less now.'));
        $this->assertTrue($closing->contains(fn (string $body) => str_starts_with($body, "That's the sprint wrapped up, Robin.")));

        $evaluation = SprintEvaluation::sole();
        $this->assertSame(EvaluationStatus::AwaitingAnswers, $evaluation->status);
        $this->assertCount(3, $evaluation->questions);
        $this->assertSame(75, $evaluation->scores['communication']);
        $this->assertSame(100, $evaluation->scores['delivery']);
        $this->assertSame(100, $evaluation->scores['process']);
        $this->assertNull($evaluation->scores['review']);
        $this->assertTrue($closing->contains(fn (string $body) => str_contains($body, 'I have three questions for your evaluation')));

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('overview', null)
            ->where('completed.scenario_title', 'Demo sprint')
            ->where('completed.evaluation.status', 'awaiting_answers')
            ->where('completed.evaluation.url', route('sprints.evaluation.show', $sprint, false)));

        $this->get(route('sprints.evaluation.show', $sprint))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('sprints/Evaluation')
                ->where('evaluated_sprint.scenario_title', 'Demo sprint')
                ->where('evaluation.status', 'awaiting_answers')
                ->where('evaluation.questions.0', 'Why did you guard the approval in the model?')
                ->has('evaluation.questions', 3)
                // The numbers come with the finished evaluation, not before the answers.
                ->where('evaluation.scores', null)
                ->where('evaluation.overall', null)
                ->where('evaluation.communication_evidence', null)
                ->where('sprint', null));

        // The user answers the three questions and the evaluation is written.
        $this->post(route('sprints.evaluation.answers', $sprint), ['answers' => [
            'The model is the one place every approval goes through.',
            'I added the for-sale option to the same filter.',
            'I would write the test first.',
        ]])->assertRedirect(route('sprints.evaluation.show', $sprint));

        $evaluation->refresh();
        $this->assertSame(EvaluationStatus::Completed, $evaluation->status);
        $this->assertSame(['The model is the one place every approval goes through.', 'I added the for-sale option to the same filter.', 'I would write the test first.'], $evaluation->answers);
        // Ratings 5, 4 and 3 are 100, 75 and 50 points.
        $this->assertSame(75, $evaluation->scores['understanding']);
        $this->assertNotNull($evaluation->overall);
        $this->assertNotNull($evaluation->passed);

        $this->get(route('sprints.evaluation.show', $sprint))->assertInertia(fn (Assert $page) => $page
            ->where('evaluation.status', 'completed')
            ->where('evaluation.scores.understanding', 75)
            ->where('evaluation.scores.communication', 75)
            ->where('evaluation.scores.delivery', 100)
            ->where('evaluation.overall', $evaluation->overall)
            ->where('evaluation.passed', $evaluation->passed)
            ->where('evaluation.strengths', 'You kept every fix small.')
            ->where('evaluation.growth', 'Write the test first.')
            ->where('evaluation.advice', 'Add a test before you open the pull request.')
            ->where('evaluation.answer_notes.0.note', 'Accurate.'));

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('completed.evaluation.status', 'completed')
            ->where('completed.evaluation.overall', $evaluation->overall)
            ->where('scenarios', fn ($scenarios) => collect($scenarios)->firstWhere('id', $demo->id)['result']['overall'] === $evaluation->overall));

        $this->assertTrue(Message::where('colleague', 'sarah')->where('body', 'like', 'Your evaluation is ready.%')->exists());
    }

    private function connectRepository(): Repository
    {
        $installation = GitHubInstallation::create([
            'installation_id' => 9001,
            'user_id' => $this->user->id,
            'account_login' => 'robin',
            'account_id' => 4242,
        ]);

        $repository = $this->giveRepository($this->user, installation: $installation);
        $repository->forceFill(['github_id' => 7001])->save();

        return $repository;
    }

    /**
     * GitHub's API: the token, the changed files of every pull request and the review call.
     */
    private function fakeGitHubApi(): void
    {
        Http::fake([
            'api.github.com/app/installations/*/access_tokens' => Http::response([
                'token' => 'installation-token',
                'expires_at' => now()->addHour()->toIso8601String(),
            ], 201),
            'api.github.com/repos/robin/workkit/pulls/*/files*' => Http::response([[
                'filename' => 'app/Models/LeaseRequest.php',
                'status' => 'modified',
                'additions' => 3,
                'deletions' => 1,
                'patch' => "@@ -10,3 +10,5 @@\n public function approve(): void\n {\n-    \$this->status = 'approved';\n+    if (\$this->status === 'approved') {\n+        return;\n+    }",
            ]]),
            'api.github.com/repos/robin/workkit/pulls/*/reviews' => Http::response(['id' => 555], 200),
        ]);
    }

    private function pushBranch(string $branch, ?string $deliveryId = null): TestResponse
    {
        return $this->githubWebhook('create', [
            'ref' => $branch,
            'ref_type' => 'branch',
            'installation' => ['id' => 9001],
            'repository' => ['id' => 7001, 'full_name' => 'robin/workkit'],
            'sender' => ['id' => 4242, 'login' => 'robin', 'type' => 'User'],
        ], $deliveryId ?? 'delivery-wk-1-branch');
    }

    private function pullRequest(string $action, int $number, string $branch, string $title, bool $merged = false): TestResponse
    {
        $payload = $this->pullRequestPayload($action, [
            'number' => $number,
            'title' => $title,
            'state' => $action === 'closed' ? 'closed' : 'open',
            'merged' => $merged,
            'head' => ['ref' => $branch],
        ]);

        return $this->githubWebhook('pull_request', $payload, 'delivery-pr-'.$number.'-'.$action.'-'.(++$this->delivery));
    }

    private function ticket(string $key): Ticket
    {
        return Ticket::where('key', $key)->sole();
    }

    private function assertTicketStatus(string $key, TicketStatus $status): void
    {
        $this->assertSame($status, $this->ticket($key)->status, "{$key} should be {$status->label()}.");
    }
}
