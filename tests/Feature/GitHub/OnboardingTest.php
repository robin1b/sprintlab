<?php

namespace Tests\Feature\GitHub;

use App\Models\GitHubInstallation;
use App\Models\User;
use App\Services\GitHub\Onboarding;
use App\Services\Scenario\ScenarioImporter;
use App\Services\Sprint\SprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\Support\GitHubFixtures;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use GitHubFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['github.app_slug' => 'sprintlab', 'sprintlab.template_repository' => 'robin1b/workkit']);
    }

    private function state(User $user): array
    {
        return app(Onboarding::class)->stateFor($user);
    }

    public function test_without_an_installation_nothing_is_connected(): void
    {
        $state = $this->state(User::factory()->create());

        $this->assertSame('robin1b/workkit', $state['template_repository']);
        $this->assertSame('https://github.com/robin1b/workkit/generate', $state['template_url']);
        $this->assertSame('https://github.com/apps/sprintlab/installations/new', $state['install_url']);
        $this->assertFalse($state['installed']);
        $this->assertFalse($state['suspended']);
        $this->assertSame([], $state['repositories']);
        $this->assertNull($state['repository']);
        $this->assertFalse($state['ready']);
    }

    public function test_an_installation_without_repositories_is_installed_but_not_ready(): void
    {
        $user = User::factory()->create();
        GitHubInstallation::create(['installation_id' => 1, 'user_id' => $user->id, 'account_login' => 'robin', 'account_id' => 1]);

        $state = $this->state($user);

        $this->assertTrue($state['installed']);
        $this->assertFalse($state['suspended']);
        $this->assertFalse($state['ready']);
        $this->assertNull($state['repository']);
    }

    public function test_one_repository_is_the_connected_one(): void
    {
        $user = User::factory()->create();
        $repository = $this->giveRepository($user, 'robin/workkit');

        $state = $this->state($user);
        $expected = ['id' => $repository->id, 'full_name' => 'robin/workkit', 'private' => false, 'url' => 'https://github.com/robin/workkit'];

        $this->assertSame([$expected], $state['repositories']);
        $this->assertSame($expected, $state['repository']);
        $this->assertTrue($state['ready']);
    }

    public function test_with_several_repositories_the_chosen_one_wins_otherwise_the_newest(): void
    {
        $user = User::factory()->create();
        $first = $this->giveRepository($user, 'robin/b-first');
        $second = $this->giveRepository($user, 'robin/a-second');

        $state = $this->state($user);

        $this->assertSame(['robin/a-second', 'robin/b-first'], array_column($state['repositories'], 'full_name'));
        $this->assertSame($second->id, $state['repository']['id']);

        $user->update(['practice_repository_id' => $first->id]);

        $this->assertSame($first->id, $this->state($user->fresh())['repository']['id']);
    }

    public function test_only_suspended_installations_are_reported_as_suspended(): void
    {
        $user = User::factory()->create();
        $this->giveRepository($user, suspended: true);

        $state = $this->state($user);

        $this->assertFalse($state['installed']);
        $this->assertTrue($state['suspended']);
        $this->assertSame([], $state['repositories']);
        $this->assertFalse($state['ready']);
    }

    public function test_installations_of_other_users_do_not_count(): void
    {
        $this->giveRepository(User::factory()->create());

        $this->assertFalse($this->state(User::factory()->create())['installed']);
    }

    public function test_an_empty_app_slug_gives_no_install_url(): void
    {
        config(['github.app_slug' => '']);

        $this->assertNull($this->state(User::factory()->create())['install_url']);
    }

    public function test_the_chosen_repository_is_preferred_and_the_fallback_works_after_it_is_removed(): void
    {
        $user = User::factory()->create();
        $chosen = $this->giveRepository($user, 'robin/chosen');
        $newest = $this->giveRepository($user, 'robin/newest');
        $user->update(['practice_repository_id' => $chosen->id]);
        $service = app(SprintService::class);

        $this->assertTrue($service->connectedRepositoryOf($user->fresh())->is($chosen));

        $chosen->delete();

        $this->assertNull($user->fresh()->practice_repository_id);
        $this->assertTrue($service->connectedRepositoryOf($user->fresh())->is($newest));
    }

    public function test_a_chosen_repository_of_a_suspended_installation_is_ignored(): void
    {
        $user = User::factory()->create();
        $chosen = $this->giveRepository($user, 'robin/chosen');
        $other = $this->giveRepository($user, 'robin/other');
        $user->update(['practice_repository_id' => $chosen->id]);
        $chosen->installation->update(['suspended_at' => now()]);

        $this->assertTrue(app(SprintService::class)->connectedRepositoryOf($user->fresh())->is($other));
    }

    public function test_an_installation_made_before_signing_up_is_linked_at_sign_in(): void
    {
        $this->githubWebhook('installation', $this->installationPayload())->assertSuccessful();
        $this->artisan('queue:work', ['--stop-when-empty' => true]);

        $installation = GitHubInstallation::query()->sole();
        $this->assertNull($installation->user_id);

        Socialite::fake('github', SocialiteUser::fake([
            'id' => '4242', 'nickname' => 'robin', 'name' => 'Robin', 'email' => 'r@example.com', 'avatar' => 'https://example.com/a.png',
        ]));

        $this->get(route('auth.github.callback'))->assertRedirect();

        $this->assertSame(User::query()->where('github_id', 4242)->value('id'), $installation->fresh()->user_id);
    }

    public function test_sign_in_does_not_take_an_installation_of_another_account(): void
    {
        $installation = GitHubInstallation::create(['installation_id' => 5, 'user_id' => null, 'account_login' => 'other', 'account_id' => 999]);
        Socialite::fake('github', SocialiteUser::fake(['id' => '4242', 'nickname' => 'robin', 'name' => 'Robin', 'email' => null, 'avatar' => null]));

        $this->get(route('auth.github.callback'))->assertRedirect();

        $this->assertNull($installation->fresh()->user_id);
    }

    public function test_coming_back_from_github_redirects_with_a_toast(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('github.installed', ['installation_id' => '1', 'setup_action' => 'install']))
            ->assertRedirect(route('dashboard'));
    }

    public function test_coming_back_from_github_flashes_the_toast(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('github.installed'))
            ->assertInertiaFlash('toast.type', 'success')
            ->assertInertiaFlash('toast.message', 'GitHub is connecting your repository. This takes a few seconds.');
    }

    public function test_guests_cannot_use_the_setup_url(): void
    {
        $this->get(route('github.installed'))->assertRedirect(route('login'));
    }

    public function test_a_user_can_choose_one_of_their_repositories(): void
    {
        $user = User::factory()->create();
        $this->giveRepository($user, 'robin/one');
        $two = $this->giveRepository($user, 'robin/two');

        $this->actingAs($user)
            ->from(route('dashboard'))
            ->put(route('practice-repository.update'), ['repository_id' => $two->id])
            ->assertRedirect(route('dashboard'));

        $this->assertSame($two->id, $user->fresh()->practice_repository_id);
    }

    public function test_a_repository_of_another_user_is_refused(): void
    {
        $user = User::factory()->create();
        $foreign = $this->giveRepository(User::factory()->create(), 'someone/else');

        $this->actingAs($user)
            ->put(route('practice-repository.update'), ['repository_id' => $foreign->id])
            ->assertSessionHasErrors(['repository_id' => 'Choose one of your connected repositories.']);

        $this->assertNull($user->fresh()->practice_repository_id);
    }

    public function test_a_repository_of_a_suspended_installation_is_refused(): void
    {
        $user = User::factory()->create();
        $suspended = $this->giveRepository($user, suspended: true);

        $this->actingAs($user)
            ->put(route('practice-repository.update'), ['repository_id' => $suspended->id])
            ->assertSessionHasErrors(['repository_id' => 'Choose one of your connected repositories.']);
    }

    public function test_choosing_a_repository_requires_sign_in(): void
    {
        $this->put(route('practice-repository.update'), ['repository_id' => 1])->assertRedirect(route('login'));
    }

    public function test_the_dashboard_passes_the_setup_state_also_during_a_sprint(): void
    {
        $user = User::factory()->create();
        $this->giveRepository($user);

        $expected = ['template_repository', 'template_url', 'install_url', 'installed', 'suspended', 'all_repositories', 'configure_url', 'repositories', 'repository', 'ready'];

        $this->actingAs($user)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('setup.ready', true)
                ->where('setup.repository.full_name', 'robin/workkit')
                ->where('setup', fn ($setup) => array_keys($setup->all()) === $expected));

        app(SprintService::class)->start($user, app(ScenarioImporter::class)->importFile(resource_path('scenarios/demo-sprint.json')));

        $this->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('setup', fn ($setup) => array_keys($setup->all()) === $expected));
    }
}
