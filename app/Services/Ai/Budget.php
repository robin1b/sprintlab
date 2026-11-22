<?php

namespace App\Services\Ai;

use App\Models\AiCall;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The monthly cap on what the AI may cost. The month is the calendar month in
 * the app's timezone.
 */
class Budget
{
    public function spentThisMonthMicros(): int
    {
        return (int) AiCall::query()
            ->where('created_at', '>=', Carbon::now()->startOfMonth())
            ->sum('cost_micros');
    }

    /**
     * Null when the budget is 0: no cap.
     */
    public function limitMicros(): ?int
    {
        $limit = (int) round((float) config('sprintlab.ai.monthly_budget_usd') * 1_000_000);

        return $limit > 0 ? $limit : null;
    }

    public function allows(): bool
    {
        $limit = $this->limitMicros();

        if ($limit === null) {
            return true;
        }

        try {
            return $this->spentThisMonthMicros() < $limit;
        } catch (Throwable $exception) {
            // No counter (yet), for example during a deploy: do not block the colleagues.
            report($exception);

            return true;
        }
    }

    public function remainingMicros(): ?int
    {
        $limit = $this->limitMicros();

        return $limit === null ? null : max(0, $limit - $this->spentThisMonthMicros());
    }
}
