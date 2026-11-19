<?php

namespace Tests\Feature\Evaluation;

use App\Enums\Colleague;
use App\Enums\ReviewStatus;
use App\Enums\ReviewVerdict;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Message;
use App\Models\ScenarioEvent;
use App\Models\Sprint;
use App\Models\User;
use App\Services\Evaluation\SprintMaterial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\EvaluationFixtures;
use Tests\TestCase;

class SprintMaterialTest extends TestCase
{
    use EvaluationFixtures, RefreshDatabase;

    private function material(Sprint $sprint): string
    {
        return app(SprintMaterial::class)->forSprint($sprint);
    }

    public function test_it_holds_the_scenario_the_tickets_the_reviews_and_the_feedback(): void
    {
        $sprint = $this->completedSprint();
        $pullRequest = $this->pullRequestWithReviews([ReviewVerdict::RequestChanges, ReviewVerdict::Approve]);
        $bug = $this->addTicket($sprint, TicketType::Bug, TicketStatus::Done, $pullRequest);
        $bug->update(['key' => 'WK-1', 'title' => 'Fix double approval']);
        $review = $this->addTicket($sprint, TicketType::Review, TicketStatus::Done);
        $review->update(['key' => 'WK-9', 'title' => 'Review the repair endpoint']);
        $this->addAssessment($review, 80, feedback: 'Good catch on the controller.');
        $unreleased = $this->addTicket($sprint, released: false);
        $unreleased->update(['title' => 'Hidden future ticket']);

        $text = $this->material($sprint);

        $this->assertStringContainsString('Scenario: Lease approvals (level: junior)', $text);
        $this->assertStringContainsString('- WK-1 | bug | Fix double approval | final status: Done', $text);
        $this->assertStringContainsString('- WK-9 | review | Review the repair endpoint | final status: Done', $text);
        $this->assertStringNotContainsString('Hidden future ticket', $text);
        $this->assertStringContainsString('WK-1 (Pull request', $text);
        $this->assertStringContainsString('1. request_changes: Summary of review 0', $text);
        $this->assertStringContainsString('2. approve: Summary of review 1', $text);
        $this->assertStringContainsString('- WK-9: Good catch on the controller.', $text);
    }

    public function test_only_posted_reviews_by_sarah_are_included(): void
    {
        $sprint = $this->completedSprint();
        $pullRequest = $this->pullRequestWithReviews([ReviewVerdict::Approve]);
        $this->addReview($pullRequest, ReviewVerdict::Comment, ReviewStatus::Skipped, 'A SKIPPED REVIEW', 1);
        $this->addReview($pullRequest, ReviewVerdict::Comment, ReviewStatus::Posted, 'A REVIEW OF JOSH', 2, Colleague::Josh);
        $this->addTicket($sprint, pullRequest: $pullRequest);

        $text = $this->material($sprint);

        $this->assertStringContainsString('approve: Summary of review 0', $text);
        $this->assertStringNotContainsString('A SKIPPED REVIEW', $text);
        $this->assertStringNotContainsString('A REVIEW OF JOSH', $text);
    }

    public function test_it_holds_the_messages_of_the_user_oldest_first_with_the_colleague(): void
    {
        $sprint = $this->completedSprint(User::factory()->create(['name' => 'Robin Broos']));
        Message::factory()->fromUser()->create(['sprint_id' => $sprint->id, 'colleague' => Colleague::Emily, 'body' => 'Where do I start?']);
        Message::factory()->create(['sprint_id' => $sprint->id, 'colleague' => Colleague::Emily, 'body' => 'A REPLY FROM EMILY']);
        Message::factory()->fromUser()->create(['sprint_id' => $sprint->id, 'colleague' => Colleague::Daniel, 'body' => 'I will be late with WK-2.']);
        Message::factory()->fromUser()->create(['body' => 'A MESSAGE IN ANOTHER SPRINT']);

        $text = $this->material($sprint);

        $this->assertStringContainsString("- to Emily: Where do I start?\n- to Daniel: I will be late with WK-2.", $text);
        $this->assertStringNotContainsString('A REPLY FROM EMILY', $text);
        $this->assertStringNotContainsString('ANOTHER SPRINT', $text);
    }

    public function test_only_the_newest_40_messages_are_included_and_each_is_cut_at_500_characters(): void
    {
        $sprint = $this->completedSprint();

        foreach (range(1, 45) as $number) {
            Message::factory()->fromUser()->create(['sprint_id' => $sprint->id, 'colleague' => Colleague::Emily, 'body' => $number === 45 ? str_repeat('y', 600) : "Message number {$number}."]);
        }

        $text = $this->material($sprint);

        $this->assertStringNotContainsString('Message number 5.', $text);
        $this->assertStringContainsString('Message number 6.', $text);
        $this->assertSame(40, substr_count($text, '- to Emily: '));
        $this->assertStringContainsString(str_repeat('y', 500), $text);
        $this->assertStringNotContainsString(str_repeat('y', 501), $text);
    }

    public function test_without_messages_it_says_so(): void
    {
        $text = $this->material($this->completedSprint());

        $this->assertStringContainsString('(no messages)', $text);
    }

    public function test_it_never_holds_planted_issues_or_event_payloads(): void
    {
        $sprint = $this->completedSprint();
        $this->addTicket($sprint, TicketType::Review, TicketStatus::Done);
        $event = ScenarioEvent::factory()->for($sprint->scenario)->create(['payload' => ['body' => 'SECRET EVENT PAYLOAD']]);

        $text = app(SprintMaterial::class)->forAnswers($sprint, ['Q1?', 'Q2?', 'Q3?'], ['A1', 'A2', 'A3']);

        $this->assertNotNull($event->id);
        $this->assertStringNotContainsString('SECRET PLANTED PROBLEM', $text);
        $this->assertStringNotContainsString('secret-id', $text);
        $this->assertStringNotContainsString('planted', strtolower($text));
        $this->assertStringNotContainsString('SECRET EVENT PAYLOAD', $text);
    }

    public function test_the_writer_material_adds_the_questions_with_their_answers(): void
    {
        $text = app(SprintMaterial::class)->forAnswers($this->completedSprint(), ['Why?', 'How?', 'What?'], ['Because.', '', 'Nothing.']);

        $this->assertStringContainsString("Question 1: Why?\nAnswer 1: Because.", $text);
        $this->assertStringContainsString("Question 2: How?\nAnswer 2: (no answer)", $text);
        $this->assertStringContainsString("Question 3: What?\nAnswer 3: Nothing.", $text);
        $this->assertStringContainsString('Scenario: Lease approvals', $text);
    }

    public function test_the_first_name_is_the_first_word_of_the_name(): void
    {
        $this->assertSame('Robin', SprintMaterial::firstName('Robin Broos'));
        $this->assertSame('Robin', SprintMaterial::firstName('  Robin  '));
        $this->assertSame('there', SprintMaterial::firstName(''));
    }
}
