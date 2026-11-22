<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\GitHubFixtures;
use Tests\Support\SprintFixtures;
use Tests\TestCase;

class BoardTest extends TestCase
{
    use GitHubFixtures;
    use RefreshDatabase;
    use SprintFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_guests_are_redirected_to_the_login_page()
    {
        $this->get(route('board'))->assertRedirect(route('login'));
    }

    public function test_without_a_sprint_the_board_is_empty()
    {
        $this->actingAs(User::factory()->create());

        $this->get(route('board'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Board')
                ->has('tickets', 0)
                ->has('statuses', 4)
                ->where('statuses.0', ['value' => 'todo', 'label' => 'To do']));
    }

    public function test_only_released_tickets_are_on_the_board()
    {
        $sprint = $this->startDemoSprint();
        $this->assertSame(3, $sprint->tickets()->count());
        $this->actingAs($sprint->user);

        $this->get(route('board'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets', 2)
                ->where('tickets.0.key', 'WK-1')
                ->where('tickets.0.status', 'todo')
                ->where('tickets.0.type', 'bug')
                ->where('tickets.0.reporter', ['key' => 'sarah', 'name' => 'Sarah', 'role' => 'Team lead'])
                ->where('tickets.0.pull_request', null)
                ->where('tickets.0.suggested_branch', 'wk-1-lease-request-approved-twice')
                ->has('tickets.0.acceptance_criteria', 3)
                ->where('tickets.1.key', 'WK-2'));
    }

    public function test_a_linked_pull_request_has_a_github_url()
    {
        $sprint = $this->startDemoSprint();
        $pullRequest = $this->createPullRequest($sprint->user);
        $this->ticket($sprint, 'WK-1')->update(['pull_request_id' => $pullRequest->id]);
        $this->actingAs($sprint->user);

        $this->get(route('board'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tickets.0.pull_request', ['number' => 12, 'url' => 'https://github.com/robin/workkit/pull/12']));
    }

    public function test_the_suggested_branch_is_at_most_50_characters()
    {
        $sprint = $this->startDemoSprint();
        $this->ticket($sprint, 'WK-1')->update([
            'title' => 'Employees receive a confirmation email with the wrong monthly amount after approval',
        ]);
        $this->actingAs($sprint->user);

        $this->get(route('board'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('tickets.0.suggested_branch', fn (string $branch) => strlen($branch) <= 50
                    && str_starts_with($branch, 'wk-1-employees-receive')
                    && ! str_ends_with($branch, '-')));
    }

    public function test_the_board_does_not_show_tickets_of_other_users()
    {
        $other = $this->startDemoSprint(User::factory()->create());
        Ticket::query()->where('sprint_id', $other->id)->update(['title' => 'Secret']);
        $sprint = $this->startDemoSprint(User::factory()->create());
        $this->actingAs($sprint->user);

        $this->get(route('board'))
            ->assertInertia(fn (Assert $page) => $page->has('tickets', 2)->where('tickets.0.title', fn ($t) => $t !== 'Secret'));
    }
}
