<?php

namespace Tests\Feature\Admin;

use App\Enums\AiFeature;
use App\Models\AiCall;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UsagePageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        config(['sprintlab.admin_github_ids' => [4242]]);

        return User::factory()->create(['github_id' => 4242]);
    }

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    public function test_a_guest_is_sent_to_the_login(): void
    {
        $this->get('/admin/usage')->assertRedirect('/login');
    }

    public function test_a_normal_user_gets_a_403(): void
    {
        config(['sprintlab.admin_github_ids' => [4242]]);

        $this->actingAs(User::factory()->create(['github_id' => 1]))->get('/admin/usage')->assertForbidden();
    }

    public function test_the_empty_state_has_no_calls(): void
    {
        config(['sprintlab.ai.monthly_budget_usd' => 10]);

        $this->actingAs($this->admin())->get('/admin/usage')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Usage')
                ->where('has_calls', false)
                ->where('spent_micros', 0)
                ->where('limit_micros', 10_000_000)
                ->where('remaining_micros', 10_000_000)
                ->where('days', [])
                ->where('models', []));
    }

    public function test_the_admin_sees_the_totals_of_this_month(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(10)->setTime(12, 0));
        config(['sprintlab.ai.monthly_budget_usd' => 10, 'sprintlab.ai.prices_checked_on' => '2026-11-15']);

        AiCall::factory()->feature(AiFeature::Chat)->create(['input_tokens' => 100, 'output_tokens' => 10, 'cost_micros' => 1000]);
        AiCall::factory()->feature(AiFeature::Chat)->create(['input_tokens' => 200, 'output_tokens' => 20, 'cost_micros' => 2000, 'created_at' => now()->subDay()]);
        AiCall::factory()->feature(AiFeature::Review)->create(['model' => 'claude-sonnet-5-5', 'input_tokens' => 5000, 'output_tokens' => 500, 'cost_micros' => 40_000]);
        AiCall::factory()->feature(AiFeature::Assessment)->unpriced()->create(['input_tokens' => 7, 'output_tokens' => 3]);
        // Last month: in the daily list (30 days back) but not in this month's totals.
        AiCall::factory()->create(['cost_micros' => 9_000_000, 'created_at' => now()->startOfMonth()->subDay()]);

        $this->actingAs($this->admin())->get('/admin/usage')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/Usage')
                ->where('has_calls', true)
                ->where('spent_micros', 43_000)
                ->where('limit_micros', 10_000_000)
                ->where('remaining_micros', 10_000_000 - 43_000)
                ->where('prices_checked_on', '2026-11-15')
                ->has('features', 3)
                ->where('features.0', ['key' => 'review', 'label' => 'Pull request review', 'calls' => 1, 'input_tokens' => 5000, 'output_tokens' => 500, 'cost_micros' => 40_000])
                ->where('features.1', ['key' => 'chat', 'label' => 'Chat reply', 'calls' => 2, 'input_tokens' => 300, 'output_tokens' => 30, 'cost_micros' => 3000])
                ->where('features.2.calls', 1)
                ->has('models', 3)
                ->where('models.0.model', 'claude-sonnet-5-5')
                ->where('models.0.priced', true)
                ->where('models.2.model', 'some-new-model')
                ->where('models.2.priced', false)
                ->where('models.2.cost_micros', 0)
                // Today, yesterday and the day last month: three days with calls.
                ->has('days', 3)
                ->where('days.0.calls', 3)
                ->where('days.0.cost_micros', 41_000)
                ->where('days.1.calls', 1)
                ->where('days.1.cost_micros', 2000));
    }
}
