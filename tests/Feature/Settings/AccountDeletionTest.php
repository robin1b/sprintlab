<?php

namespace Tests\Feature\Settings;

use App\Enums\ReviewVerdict;
use App\Enums\TicketType;
use App\Jobs\RemoveGitHubInstallation;
use App\Models\AiCall;
use App\Models\GitHubInstallation;
use App\Models\Message;
use App\Models\PullRequest;
use App\Models\Repository;
use App\Models\Review;
use App\Models\ReviewAssessment;
use App\Models\Sprint;
use App\Models\SprintEvaluation;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\EvaluationFixtures;
use Tests\Support\GitHubFixtures;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    use EvaluationFixtures;
    use GitHubFixtures;
    use RefreshDatabase;

    /**
     * @return array{sprint: Sprint, ticket: Ticket, pullRequest: PullRequest, aiCall: AiCall}
     */
    private function giveData(User $user, PullRequest $pullRequest): array
    {
        $sprint = $this->completedSprint($user);
        $ticket = $this->addTicket($sprint, TicketType::Review, pullRequest: $pullRequest);
        Message::factory()->for($sprint)->create();
        SprintEvaluation::factory()->for($sprint)->create();
        $this->addReview($pullRequest, ReviewVerdict::Approve, commit: 100 + $user->id);
        $this->addAssessment($ticket, 80);

        $aiCall = AiCall::factory()->create(['user_id' => $user->id, 'sprint_id' => $sprint->id]);

        DB::table('sessions')->insert([
            'id' => 'session-'.$user->id,
            'user_id' => $user->id,
            'payload' => '',
            'last_activity' => time(),
        ]);

        return compact('sprint', 'ticket', 'pullRequest', 'aiCall');
    }

    public function test_deleting_the_account_removes_everything_of_the_user_and_nothing_of_others(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $mine = $this->giveData($user, $this->createPullRequest($user));

        $other = User::factory()->create();
        $theirPullRequest = $this->pullRequestWithReviews([ReviewVerdict::Approve]);
        GitHubInstallation::where('installation_id', '!=', 9001)->update(['user_id' => $other->id]);
        $theirs = $this->giveData($other, $theirPullRequest);

        $this->actingAs($user)
            ->delete(route('profile.destroy'))
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertModelMissing($user);
        $this->assertModelMissing($mine['sprint']);
        $this->assertModelMissing($mine['ticket']);
        $this->assertModelMissing($mine['pullRequest']);
        $this->assertDatabaseMissing('messages', ['sprint_id' => $mine['sprint']->id]);
        $this->assertDatabaseMissing('sprint_evaluations', ['sprint_id' => $mine['sprint']->id]);
        $this->assertDatabaseMissing('github_installations', ['installation_id' => 9001]);
        $this->assertDatabaseMissing('repositories', ['github_id' => 7001]);
        $this->assertDatabaseMissing('reviews', ['pull_request_id' => $mine['pullRequest']->id]);
        $this->assertDatabaseMissing('review_assessments', ['ticket_id' => $mine['ticket']->id]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);

        // The anonymous AI counter stays, without the link to the person.
        $this->assertDatabaseHas('ai_calls', ['id' => $mine['aiCall']->id, 'user_id' => null, 'sprint_id' => null]);

        // The other user is untouched.
        $this->assertModelExists($other);
        $this->assertModelExists($theirs['sprint']);
        $this->assertModelExists($theirs['ticket']);
        $this->assertModelExists($theirs['pullRequest']);
        $this->assertSame(1, Message::where('sprint_id', $theirs['sprint']->id)->count());
        $this->assertSame(1, SprintEvaluation::where('sprint_id', $theirs['sprint']->id)->count());
        $this->assertSame(2, Review::where('pull_request_id', $theirs['pullRequest']->id)->count());
        $this->assertSame(1, ReviewAssessment::where('ticket_id', $theirs['ticket']->id)->count());
        $this->assertSame(1, Repository::count());
        $this->assertSame(1, GitHubInstallation::where('user_id', $other->id)->count());
        $this->assertDatabaseHas('sessions', ['user_id' => $other->id]);
        $this->assertDatabaseHas('ai_calls', ['id' => $theirs['aiCall']->id, 'user_id' => $other->id]);

        Queue::assertPushed(RemoveGitHubInstallation::class, 1);
        Queue::assertPushed(RemoveGitHubInstallation::class, fn (RemoveGitHubInstallation $job) => $job->installationId === 9001);
    }

    public function test_a_user_without_an_installation_is_deleted_without_a_github_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $this->actingAs($user)->delete(route('profile.destroy'))->assertRedirect('/');

        $this->assertModelMissing($user);
        Queue::assertNothingPushed();
    }
}
