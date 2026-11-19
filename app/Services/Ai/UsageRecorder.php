<?php

namespace App\Services\Ai;

use App\Enums\AiFeature;
use App\Models\AiCall;
use App\Models\Sprint;
use App\Models\User;
use Throwable;

/**
 * Writes down every call to the model.
 */
class UsageRecorder
{
    public function __construct(private readonly Pricing $pricing) {}

    public function record(
        AiFeature $feature,
        string $model,
        int $inputTokens,
        int $outputTokens,
        ?User $user = null,
        ?Sprint $sprint = null,
    ): void {
        try {
            $cost = $this->pricing->costMicros($model, $inputTokens, $outputTokens);

            AiCall::create([
                'user_id' => $user?->id,
                'sprint_id' => $sprint?->id,
                'feature' => $feature,
                'model' => $model,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'cost_micros' => $cost ?? 0,
                'priced' => $cost !== null,
            ]);
        } catch (Throwable $exception) {
            // Counting must never break a reply or a review (for example when
            // the table does not exist yet during a deploy).
            report($exception);
        }
    }
}
