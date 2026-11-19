<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Pricing;
use Tests\TestCase;

class PricingTest extends TestCase
{
    public function test_a_known_model_is_priced_per_million_tokens(): void
    {
        // Sonnet: $2 in, $10 out per million tokens.
        $this->assertSame(12_000_000, (new Pricing)->costMicros('claude-sonnet-5-5', 1_000_000, 1_000_000));
        $this->assertSame(2_000_000, (new Pricing)->costMicros('claude-sonnet-5-5', 1_000_000, 0));
    }

    public function test_a_model_name_with_a_date_suffix_uses_the_price_of_its_family(): void
    {
        $this->assertSame(
            (new Pricing)->costMicros('claude-sonnet-5-5', 5000, 800),
            (new Pricing)->costMicros('claude-sonnet-5-5-20261001', 5000, 800),
        );
    }

    public function test_an_unknown_model_has_no_price(): void
    {
        $this->assertNull((new Pricing)->costMicros('some-other-model', 1000, 1000));
    }

    public function test_the_cost_is_rounded_up_to_the_next_micro(): void
    {
        // Haiku: $0.10 in per million = 0.1 micro per token: 1 token is a tenth of a micro, counted as 1.
        $this->assertSame(1, (new Pricing)->costMicros('claude-haiku-5-5', 1, 0));
        // 11 input tokens = 1.1 micros, rounded up to 2.
        $this->assertSame(2, (new Pricing)->costMicros('claude-haiku-5-5', 11, 0));
        // Exactly 1 micro stays 1.
        $this->assertSame(1, (new Pricing)->costMicros('claude-haiku-5-5', 10, 0));
        $this->assertSame(0, (new Pricing)->costMicros('claude-haiku-5-5', 0, 0));
    }

    public function test_the_longest_matching_key_wins(): void
    {
        config(['sprintlab.ai.prices' => [
            'claude-sonnet-5-5' => ['input' => 2.0, 'output' => 10.0],
            'claude-sonnet-5-5-mini' => ['input' => 1.0, 'output' => 5.0],
        ]]);

        $this->assertSame(1_000_000, (new Pricing)->costMicros('claude-sonnet-5-5-mini-20261001', 1_000_000, 0));
    }
}
