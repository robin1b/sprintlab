<?php

namespace Tests\Feature\Review;

use App\Ai\Agents\ReviewAssessor;
use App\Enums\AiFeature;
use App\Enums\AssessmentStatus;
use App\Enums\Colleague;
use App\Enums\ReviewVerdict;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Jobs\AssessUserReview;
use App\Models\AiCall;
use App\Models\Message;
use App\Models\ReviewAssessment;
use App\Models\Scenario;
use App\Models\ScenarioTicket;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Review\UserReviewAssessmentService;
use App\Services\Sprint\SprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Prompts\AgentPrompt;
use Tests\Support\GitHubFixtures;
use Tests\Support\ReviewFixtures;
use Tests\TestCase;

class UserReviewAssessmentTest extends TestCase
{
    use GitHubFixtures, RefreshDatabase, ReviewFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureGitHubApp();
    }

    private function assess(Ticket $ticket, int $reviewId = 777): ?ReviewAssessment
    {
        return app(UserReviewAssessmentService::class)->assess($ticket, $reviewId);
    }

    public function test_the_result_is_stored_and_sarah_sends_her_feedback_once(): void
    {
        $ticket = $this->createReviewTicket();
        $this->fakeGitHubReview();
        ReviewAssessor::fake([$this->assessorAnswer(['clarity' => 4, 'false_positives' => ['The method name is fine.']])]);

        $assessment = $this->assess($ticket);

        $this->assertSame(AssessmentStatus::Done, $assessment?->status);
        $this->assertSame(ReviewVerdict::RequestChanges, $assessment->verdict);
        $this->assertSame('robin', $assessment->reviewer_login);
        $this->assertSame($ticket->pull_request_id, $assessment->pull_request_id);
        // 70 + 4/5*15 + 15 - 5 false positive
        $this->assertSame(92, $assessment->score);
        $this->assertSame(4, $assessment->clarity);
        $this->assertSame(['The method name is fine.'], $assessment->false_positives);
        $this->assertSame('authorization', $assessment->issues[0]['id']);
        $this->assertTrue($assessment->issues[0]['found']);

        $message = Message::where('ticket_id', $ticket->id)->sole();
        $this->assertSame(Colleague::Sarah, $message->colleague);
        $this->assertFalse($message->from_user);
        $this->assertSame($ticket->sprint_id, $message->sprint_id);
        $this->assertSame('Good catch on the controller. Have another look at the naming.', $message->body);
    }

    public function test_a_score_at_the_pass_mark_moves_the_ticket_to_done(): void
    {
        config(['sprintlab.ai.review_pass_score' => 50]);
        $ticket = $this->createReviewTicket();
        $this->fakeGitHubReview();
        // Only the high problem: 3/6*70 = 35, plus 15 for clarity and tone of 5/2...
        ReviewAssessor::fake([$this->assessorAnswer([
            'issues' => [
                ['id' => 'authorization', 'found' => true, 'evidence' => 'x'],
                ['id' => 'validation', 'found' => false, 'evidence' => ''],
                ['id' => 'naming', 'found' => false, 'evidence' => ''],
            ],
            'clarity' => 3,
            'tone' => 3,
        ])]);

        // Only the high problem found: 35 points, plus 9 for clarity and 9 for tone.
        $assessment = $this->assess($ticket);

        $this->assertSame(53, $assessment?->score);
        $this->assertSame(TicketStatus::Done, $ticket->refresh()->status);
    }

    public function test_a_score_below_the_pass_mark_leaves_the_ticket_where_it_is(): void
    {
        $ticket = $this->createReviewTicket();
        $this->fakeGitHubReview();
        ReviewAssessor::fake([$this->assessorAnswer([
            'issues' => [['id' => 'naming', 'found' => true, 'evidence' => 'x']],
            'clarity' => 2,
            'tone' => 3,
        ])]);

        // 1/6*70 = 11.7, plus 6 and 9 = 26.7
        $assessment = $this->assess($ticket);

        $this->assertSame(27, $assessment?->score);
        $this->assertSame(TicketStatus::InProgress, $ticket->refresh()->status);
        $this->assertSame(1, Message::where('ticket_id', $ticket->id)->count());
    }

    public function test_a_paused_sprint_keeps_the_assessment_but_not_the_move(): void
    {
        $ticket = $this->createReviewTicket();
        app(SprintService::class)->pause($ticket->sprint);
        $this->fakeGitHubReview();
        ReviewAssessor::fake([$this->assessorAnswer()]);

        $assessment = $this->assess($ticket);

        $this->assertSame(AssessmentStatus::Done, $assessment?->status);
        $this->assertSame(TicketStatus::InProgress, $ticket->refresh()->status);
    }

    public function test_an_approval_that_misses_the_high_problem_cannot_pass(): void
    {
        $ticket = $this->createReviewTicket();
        $this->fakeGitHubReview(state: 'APPROVED');
        ReviewAssessor::fake([$this->assessorAnswer([
            'issues' => [
                ['id' => 'authorization', 'found' => false, 'evidence' => ''],
                ['id' => 'validation', 'found' => true, 'evidence' => 'x'],
                ['id' => 'naming', 'found' => true, 'evidence' => 'x'],
            ],
        ])]);

        $assessment = $this->assess($ticket);

        $this->assertSame(ReviewVerdict::Approve, $assessment?->verdict);
        $this->assertSame(49, $assessment->score);
        $this->assertSame(TicketStatus::InProgress, $ticket->refresh()->status);
    }

    public function test_unknown_issue_ids_are_dropped_and_missing_ones_count_as_not_found(): void
    {
        $ticket = $this->createReviewTicket();
        $this->fakeGitHubReview();
        ReviewAssessor::fake([$this->assessorAnswer([
            'issues' => [
                ['id' => 'invented', 'found' => true, 'evidence' => 'x'],
                ['id' => 'authorization', 'found' => true, 'evidence' => 'Anyone can update.'],
            ],
            'clarity' => 9,
            'tone' => -2,
            'false_positives' => ['One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven'],
        ])]);

        $assessment = $this->assess($ticket);

        $this->assertSame([
            ['id' => 'authorization', 'found' => true, 'evidence' => 'Anyone can update.'],
            ['id' => 'validation', 'found' => false, 'evidence' => ''],
            ['id' => 'naming', 'found' => false, 'evidence' => ''],
        ], $assessment?->issues);
        $this->assertSame(5, $assessment->clarity);
        $this->assertSame(1, $assessment->tone);
        $this->assertCount(5, $assessment->false_positives);
    }

    public function test_the_model_gets_the_planted_issues_the_review_the_comments_and_the_diff(): void
    {
        $ticket = $this->createReviewTicket();
        $this->fakeGitHubReview(state: 'APPROVED', comments: [
            ['path' => 'app/Http/Controllers/RepairController.php', 'line' => 12, 'body' => 'Mass assignment here.'],
        ]);
        ReviewAssessor::fake([$this->assessorAnswer()]);

        $this->assess($ticket);

        ReviewAssessor::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('id: authorization | severity: high | file: app/Http/Controllers/RepairController.php | Any logged-in user can update any repair.')
            && $prompt->contains('Unclear variable names.')
            && $prompt->contains('Fix double approval of lease requests')
            && $prompt->contains('Verdict: approved')
            && $prompt->contains('The controller lets anyone update any repair.')
            && $prompt->contains('app/Http/Controllers/RepairController.php:12: Mass assignment here.')
            && $prompt->contains('File: app/Http/Controllers/RepairController.php')
            && $prompt->contains('+    12 |     $repair->update(request()->all());'));
    }

    public function test_the_same_review_is_never_assessed_or_messaged_twice(): void
    {
        $ticket = $this->createReviewTicket();
        $this->fakeGitHubReview();
        ReviewAssessor::fake([$this->assessorAnswer()]);

        $first = $this->assess($ticket);
        $second = $this->assess($ticket);

        $this->assertSame($first?->id, $second?->id);
        $this->assertSame(1, ReviewAssessment::count());
        $this->assertSame(1, Message::where('ticket_id', $ticket->id)->count());
        ReviewAssessor::assertPromptedTimes(1);
    }

    public function test_a_second_better_review_gets_its_own_assessment(): void
    {
        $ticket = $this->createReviewTicket();
        $this->fakeGitHubReview(777, body: 'Looks fine to me.');
        $this->fakeGitHubReview(778, body: 'Anyone can update any repair, and the status is not validated.');
        ReviewAssessor::fake([
            $this->assessorAnswer([
                'issues' => [['id' => 'naming', 'found' => true, 'evidence' => 'x']],
                'clarity' => 2,
                'tone' => 3,
            ]),
            $this->assessorAnswer(),
        ]);

        $first = $this->assess($ticket, 777);
        $this->assertSame(TicketStatus::InProgress, $ticket->refresh()->status);

        $second = $this->assess($ticket, 778);

        $this->assertNotSame($first?->id, $second?->id);
        $this->assertSame(2, ReviewAssessment::count());
        $this->assertSame(100, $second->score);
        $this->assertSame(TicketStatus::Done, $ticket->refresh()->status);
        $this->assertSame(2, Message::where('ticket_id', $ticket->id)->count());
    }

    public function test_nothing_happens_while_ai_is_switched_off(): void
    {
        config(['sprintlab.ai.enabled' => false]);
        $ticket = $this->createReviewTicket();
        $this->fakeGitHubReview();
        ReviewAssessor::fake([$this->assessorAnswer()]);

        $this->assertNull($this->assess($ticket));
        $this->assertSame(0, ReviewAssessment::count());
        Http::assertNothingSent();
    }

    public function test_a_scenario_ticket_without_planted_issues_is_not_assessed(): void
    {
        $ticket = $this->createReviewTicket();
        $ticket->scenarioTicket->update(['planted_issues' => null]);
        $this->fakeGitHubReview();

        $this->assertNull($this->assess($ticket->refresh()));
        $this->assertSame(0, ReviewAssessment::count());
        Http::assertNothingSent();
    }

    public function test_a_ticket_that_is_not_a_review_ticket_is_not_assessed(): void
    {
        $ticket = $this->createReviewTicket();
        $ticket->update(['type' => TicketType::Bug]);
        $this->fakeGitHubReview();

        $this->assertNull($this->assess($ticket));
        Http::assertNothingSent();
    }

    public function test_a_ticket_without_a_pull_request_is_not_assessed(): void
    {
        $ticket = $this->createReviewTicket();
        $ticket->update(['pull_request_id' => null]);
        $this->fakeGitHubReview();

        $this->assertNull($this->assess($ticket));
        Http::assertNothingSent();
    }

    public function test_a_review_that_is_not_submitted_is_skipped(): void
    {
        $ticket = $this->createReviewTicket();
        $this->fakeGitHubReview(state: 'PENDING');
        ReviewAssessor::fake([$this->assessorAnswer()]);

        $assessment = $this->assess($ticket);

        $this->assertSame(AssessmentStatus::Skipped, $assessment?->status);
        $this->assertNotEmpty($assessment->error);
        ReviewAssessor::assertNeverPrompted();
        $this->assertSame(0, Message::count());
    }

    public function test_a_review_without_text_and_comments_is_skipped(): void
    {
        $ticket = $this->createReviewTicket();
        $this->fakeGitHubReview(state: 'APPROVED', body: null);
        ReviewAssessor::fake([$this->assessorAnswer()]);

        $assessment = $this->assess($ticket);

        $this->assertSame(AssessmentStatus::Skipped, $assessment?->status);
        $this->assertSame(ReviewVerdict::Approve, $assessment->verdict);
        ReviewAssessor::assertNeverPrompted();
        $this->assertSame(TicketStatus::InProgress, $ticket->refresh()->status);
    }

    public function test_a_review_with_only_inline_comments_is_assessed(): void
    {
        $ticket = $this->createReviewTicket();
        $this->fakeGitHubReview(state: 'COMMENTED', body: '', comments: [
            ['path' => 'app/Http/Controllers/RepairController.php', 'line' => null, 'body' => 'Who may call this?'],
        ]);
        ReviewAssessor::fake([$this->assessorAnswer()]);

        $this->assertSame(AssessmentStatus::Done, $this->assess($ticket)?->status);
        ReviewAssessor::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('app/Http/Controllers/RepairController.php: Who may call this?'));
    }

    public function test_a_github_failure_marks_the_assessment_failed_and_the_retry_succeeds(): void
    {
        $ticket = $this->createReviewTicket();
        $this->fakeGitHubReview(reviewStatus: 500);
        ReviewAssessor::fake([$this->assessorAnswer()]);

        try {
            $this->assess($ticket);
            $this->fail('The failed GitHub call should have thrown.');
        } catch (RequestException) {
            $assessment = ReviewAssessment::sole();
            $this->assertSame(AssessmentStatus::Failed, $assessment->status);
            $this->assertNotEmpty($assessment->error);
            $this->assertLessThanOrEqual(1000, mb_strlen($assessment->error));
        }

        Http::swap(new Factory);
        $this->fakeGitHubReview();

        $this->assertSame(AssessmentStatus::Done, $this->assess($ticket)?->status);
        $this->assertSame(1, ReviewAssessment::count());
        $this->assertNull(ReviewAssessment::sole()->error);
        $this->assertSame(1, Message::where('ticket_id', $ticket->id)->count());
    }

    public function test_the_job_assesses_the_review(): void
    {
        $ticket = $this->createReviewTicket();
        $this->fakeGitHubReview();
        ReviewAssessor::fake([$this->assessorAnswer()]);

        AssessUserReview::dispatchSync($ticket->id, 777);

        $this->assertSame(AssessmentStatus::Done, ReviewAssessment::sole()->status);
    }

    public function test_the_planted_issues_never_leave_the_scenario(): void
    {
        $secret = 'SECRETPLANTEDPROBLEM';
        $user = User::factory()->create();
        $scenario = Scenario::factory()->create();
        ScenarioTicket::factory()->for($scenario)->withPlantedIssues([
            ['id' => 'authorization', 'description' => $secret, 'severity' => 'high'],
        ])->create(['key' => 'WK-9', 'released_at_start' => true]);

        $sprint = app(SprintService::class)->start($user, $scenario);

        $this->assertNotContains('planted_issues', Schema::getColumnListing('tickets'));
        $this->assertStringNotContainsString($secret, (string) json_encode(DB::table('tickets')->get()));

        $this->withoutVite()->actingAs($user);
        $response = $this->get(route('board'))->assertOk();

        $this->assertSame(1, $sprint->tickets()->count());
        $this->assertStringContainsString('WK-9', $response->getContent());
        $this->assertStringNotContainsString($secret, $response->getContent());
        $this->assertStringNotContainsString('planted', $response->getContent());
    }

    public function test_the_assessment_is_counted_as_an_ai_call_with_the_sprint_and_its_user(): void
    {
        $ticket = $this->createReviewTicket();
        $this->fakeGitHubReview();
        ReviewAssessor::fake([$this->assessorAnswer()]);

        $assessment = $this->assess($ticket);

        $call = AiCall::sole();
        $this->assertSame(AiFeature::Assessment, $call->feature);
        $this->assertSame($ticket->sprint_id, $call->sprint_id);
        $this->assertSame($ticket->sprint->user_id, $call->user_id);
        // The fake decides the model and the (zero) tokens: the call must match what the assessment stored.
        $this->assertNotSame('', $call->model);
        $this->assertSame($assessment->model, $call->model);
        $this->assertSame($assessment->input_tokens, $call->input_tokens);
        $this->assertSame($assessment->output_tokens, $call->output_tokens);
    }

    public function test_the_assessment_is_skipped_without_calling_the_model_when_the_budget_is_reached(): void
    {
        config(['sprintlab.ai.monthly_budget_usd' => 1]);
        AiCall::factory()->create(['cost_micros' => 1_000_000]);
        $ticket = $this->createReviewTicket();
        $this->fakeGitHubReview();
        ReviewAssessor::fake([$this->assessorAnswer()]);

        $assessment = $this->assess($ticket);

        $this->assertSame(AssessmentStatus::Skipped, $assessment->status);
        $this->assertSame('Monthly AI budget reached.', $assessment->error);
        ReviewAssessor::assertNeverPrompted();
        $this->assertSame(0, Message::where('ticket_id', $ticket->id)->count());
        $this->assertSame(1, AiCall::count());
    }
}
