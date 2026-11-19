<?php

namespace Tests\Unit;

use App\Enums\ReviewVerdict;
use App\Services\Review\UserReviewAssessmentService;
use PHPUnit\Framework\TestCase;

class ReviewScoreTest extends TestCase
{
    /**
     * One high (weight 3), one medium (2) and one low (1) problem.
     *
     * @return list<array{severity: string, found: bool}>
     */
    private function issues(bool $high, bool $medium, bool $low): array
    {
        return [
            ['severity' => 'high', 'found' => $high],
            ['severity' => 'medium', 'found' => $medium],
            ['severity' => 'low', 'found' => $low],
        ];
    }

    public function test_a_perfect_review_scores_100(): void
    {
        $this->assertSame(100, UserReviewAssessmentService::score($this->issues(true, true, true), 5, 5, 0, ReviewVerdict::RequestChanges));
    }

    public function test_finding_nothing_leaves_only_the_points_for_clarity_and_tone(): void
    {
        $this->assertSame(6, UserReviewAssessmentService::score($this->issues(false, false, false), 1, 1, 0, ReviewVerdict::Comment));
    }

    public function test_problems_are_weighted_by_severity(): void
    {
        // High and medium found: 5 of 6 weight = 58.3 points, plus 12 + 12.
        $this->assertSame(82, UserReviewAssessmentService::score($this->issues(true, true, false), 4, 4, 0, ReviewVerdict::RequestChanges));
        // Only the low one found: 1 of 6 = 11.7 points, plus 12 + 12.
        $this->assertSame(36, UserReviewAssessmentService::score($this->issues(false, false, true), 4, 4, 0, ReviewVerdict::RequestChanges));
    }

    public function test_every_false_positive_costs_five_points(): void
    {
        $this->assertSame(72, UserReviewAssessmentService::score($this->issues(true, true, false), 4, 4, 2, ReviewVerdict::RequestChanges));
    }

    public function test_false_positives_cost_at_most_15_points(): void
    {
        $three = UserReviewAssessmentService::score($this->issues(false, false, false), 5, 5, 3, ReviewVerdict::Comment);
        $five = UserReviewAssessmentService::score($this->issues(false, false, false), 5, 5, 5, ReviewVerdict::Comment);

        $this->assertSame(15, $three);
        $this->assertSame(15, $five);
    }

    public function test_the_score_never_drops_below_zero(): void
    {
        $this->assertSame(0, UserReviewAssessmentService::score($this->issues(false, false, false), 1, 1, 3, ReviewVerdict::Comment));
    }

    public function test_the_score_never_rises_above_100(): void
    {
        $this->assertSame(100, UserReviewAssessmentService::score($this->issues(true, true, true), 10, 10, 0, ReviewVerdict::Comment));
    }

    public function test_approving_while_missing_a_high_problem_caps_the_score_at_49(): void
    {
        $issues = $this->issues(false, true, true); // 3 of 6 = 35 points, plus 30

        $this->assertSame(49, UserReviewAssessmentService::score($issues, 5, 5, 0, ReviewVerdict::Approve));
        $this->assertSame(65, UserReviewAssessmentService::score($issues, 5, 5, 0, ReviewVerdict::RequestChanges));
        $this->assertSame(65, UserReviewAssessmentService::score($issues, 5, 5, 0, ReviewVerdict::Comment));
    }

    public function test_the_cap_does_not_raise_a_lower_score(): void
    {
        $this->assertSame(6, UserReviewAssessmentService::score($this->issues(false, false, false), 1, 1, 0, ReviewVerdict::Approve));
    }

    public function test_approving_is_fine_when_only_smaller_problems_were_missed(): void
    {
        $this->assertSame(77, UserReviewAssessmentService::score($this->issues(true, false, true), 5, 5, 0, ReviewVerdict::Approve));
    }

    public function test_a_list_without_problems_scores_only_clarity_and_tone(): void
    {
        $this->assertSame(30, UserReviewAssessmentService::score([], 5, 5, 0, null));
    }
}
