<?php

namespace Tests\Unit;

use App\Services\Evaluation\ScoreCalculator;
use Tests\TestCase;

class ScoreCalculatorTest extends TestCase
{
    private function calculator(): ScoreCalculator
    {
        return new ScoreCalculator;
    }

    public function test_ratings_are_converted_to_scores(): void
    {
        $this->assertSame(0, $this->calculator()->ratingToScore(1));
        $this->assertSame(25, $this->calculator()->ratingToScore(2));
        $this->assertSame(50, $this->calculator()->ratingToScore(3));
        $this->assertSame(75, $this->calculator()->ratingToScore(4));
        $this->assertSame(100, $this->calculator()->ratingToScore(5));
    }

    public function test_a_rating_outside_the_range_is_clamped(): void
    {
        $this->assertSame(0, $this->calculator()->ratingToScore(0));
        $this->assertSame(100, $this->calculator()->ratingToScore(9));
    }

    public function test_overall_is_the_weighted_average_of_all_areas(): void
    {
        config(['sprintlab.evaluation.weights' => [
            'delivery' => 20, 'code_quality' => 25, 'review' => 15,
            'communication' => 15, 'process' => 10, 'understanding' => 15,
        ]]);

        $overall = $this->calculator()->overall([
            'delivery' => 100, 'code_quality' => 80, 'review' => 60,
            'communication' => 50, 'process' => 100, 'understanding' => 0,
        ]);

        // (2000 + 2000 + 900 + 750 + 1000 + 0) / 100
        $this->assertSame(67, $overall);
    }

    public function test_overall_skips_missing_areas_and_their_weight(): void
    {
        config(['sprintlab.evaluation.weights' => ['delivery' => 20, 'code_quality' => 25, 'review' => 15]]);

        // Only delivery (20) and code quality (25) count: (100*20 + 40*25) / 45
        $this->assertSame(67, $this->calculator()->overall(['delivery' => 100, 'code_quality' => 40, 'review' => null]));
        $this->assertSame(100, $this->calculator()->overall(['delivery' => 100]));
    }

    public function test_overall_is_null_when_nothing_was_judged(): void
    {
        $this->assertNull($this->calculator()->overall([]));
        $this->assertNull($this->calculator()->overall(['delivery' => null, 'review' => null]));
    }

    public function test_an_area_with_score_zero_still_counts(): void
    {
        config(['sprintlab.evaluation.weights' => ['delivery' => 50, 'review' => 50]]);

        $this->assertSame(50, $this->calculator()->overall(['delivery' => 100, 'review' => 0]));
    }
}
