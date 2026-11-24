<?php

namespace Tests\Feature;

use App\Enums\Colleague;
use App\Enums\ReviewStatus;
use App\Enums\ReviewVerdict;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\GitHubFixtures;
use Tests\Support\SprintFixtures;
use Tests\TestCase;

class BoardGuidedTest extends TestCase
{
    use GitHubFixtures;
    use RefreshDatabase;
    use SprintFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function review(int $pullRequestId, ReviewStatus $status, ?ReviewVerdict $verdict, string $sha = 'a'): Review
    {
        return Review::create([
            'pull_request_id' => $pullRequestId,
            'colleague' => Colleague::Sarah,
            'head_sha' => str_repeat($sha, 40),
            'status' => $status,
            'verdict' => $verdict,
        ]);
    }

    public function test_the_guided_ticket_is_marked_on_the_board(): void
    {
        $sprint = $this->startDemoSprint();

        $this->actingAs($sprint->user)->get(route('board'))->assertInertia(fn (Assert $page) => $page
            ->where('tickets.0.key', 'WK-0')
            ->where('tickets.0.guided', true)
            ->where('tickets.0.suggested_branch', 'wk-0-add-yourself-team-in-readme')
            ->where('tickets.1.guided', false));
    }

    public function test_the_review_of_a_pull_request_is_null_without_a_review_and_the_latest_otherwise(): void
    {
        $sprint = $this->startDemoSprint();
        $pullRequest = $this->createPullRequest($sprint->user);
        $this->ticket($sprint, 'WK-0')->update(['pull_request_id' => $pullRequest->id]);
        $this->actingAs($sprint->user);

        $this->get(route('board'))->assertInertia(fn (Assert $page) => $page
            ->where('tickets.0.pull_request.review', null));

        $this->review($pullRequest->id, ReviewStatus::Posted, ReviewVerdict::RequestChanges);
        $this->review($pullRequest->id, ReviewStatus::Pending, null, 'b');

        $this->get(route('board'))->assertInertia(fn (Assert $page) => $page
            ->where('tickets.0.pull_request.review', ['status' => 'pending', 'verdict' => null]));

        $this->review($pullRequest->id, ReviewStatus::Posted, ReviewVerdict::Approve, 'c');

        $this->get(route('board'))->assertInertia(fn (Assert $page) => $page
            ->where('tickets.0.pull_request.review', ['status' => 'posted', 'verdict' => 'approve']));
    }

    public function test_the_repository_is_the_one_of_the_sprint(): void
    {
        $user = User::factory()->create(['github_id' => 4242]);
        $this->giveRepository($user, 'robin/other');
        $sprint = $this->startDemoSprint($user);
        $own = $this->giveRepository($user, 'robin/sprint-repo');
        $sprint->update(['repository_id' => $own->id]);

        $this->actingAs($user)->get(route('board'))->assertInertia(fn (Assert $page) => $page
            ->where('repository', ['full_name' => 'robin/sprint-repo', 'url' => 'https://github.com/robin/sprint-repo']));
    }

    public function test_without_a_sprint_the_connected_practice_repository_is_used(): void
    {
        $user = User::factory()->create(['github_id' => 4242]);
        $this->giveRepository($user, 'robin/workkit');

        $this->actingAs($user)->get(route('board'))->assertInertia(fn (Assert $page) => $page
            ->where('repository', ['full_name' => 'robin/workkit', 'url' => 'https://github.com/robin/workkit']));
    }

    public function test_without_any_repository_it_is_null(): void
    {
        $sprint = $this->startDemoSprint();

        $this->actingAs($sprint->user)->get(route('board'))->assertInertia(fn (Assert $page) => $page
            ->where('repository', null));
    }
}
