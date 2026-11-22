<?php

namespace Tests\Feature\Ai;

use App\Models\AiCall;
use App\Services\Ai\Budget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->travelBack();

        parent::tearDown();
    }

    public function test_it_allows_calls_below_the_limit(): void
    {
        config(['sprintlab.ai.monthly_budget_usd' => 2]);
        AiCall::factory()->create(['cost_micros' => 1_999_999]);

        $budget = new Budget;

        $this->assertTrue($budget->allows());
        $this->assertSame(2_000_000, $budget->limitMicros());
        $this->assertSame(1_999_999, $budget->spentThisMonthMicros());
        $this->assertSame(1, $budget->remainingMicros());
    }

    public function test_it_stops_at_the_limit_and_above(): void
    {
        config(['sprintlab.ai.monthly_budget_usd' => 2]);
        AiCall::factory()->create(['cost_micros' => 2_000_000]);

        $this->assertFalse((new Budget)->allows());
        $this->assertSame(0, (new Budget)->remainingMicros());

        AiCall::factory()->create(['cost_micros' => 500_000]);

        $this->assertFalse((new Budget)->allows());
        $this->assertSame(0, (new Budget)->remainingMicros());
    }

    public function test_a_budget_of_zero_means_no_cap(): void
    {
        config(['sprintlab.ai.monthly_budget_usd' => 0]);
        AiCall::factory()->create(['cost_micros' => 999_000_000]);

        $budget = new Budget;

        $this->assertNull($budget->limitMicros());
        $this->assertNull($budget->remainingMicros());
        $this->assertTrue($budget->allows());
    }

    public function test_calls_of_last_month_do_not_count(): void
    {
        $this->travelTo(now()->startOfMonth()->addDays(10));
        config(['sprintlab.ai.monthly_budget_usd' => 1]);
        AiCall::factory()->at(now()->startOfMonth()->subSecond())->create(['cost_micros' => 5_000_000]);
        AiCall::factory()->at(now()->startOfMonth())->create(['cost_micros' => 300_000]);

        $budget = new Budget;

        $this->assertSame(300_000, $budget->spentThisMonthMicros());
        $this->assertTrue($budget->allows());
    }
}
