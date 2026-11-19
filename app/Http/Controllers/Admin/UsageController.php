<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AiFeature;
use App\Http\Controllers\Controller;
use App\Models\AiCall;
use App\Services\Ai\Budget;
use App\Support\Deploy\DeployStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the AI costs this month, for the owner. Everything is summed in the
 * database: the table grows with every reply.
 */
class UsageController extends Controller
{
    public function __invoke(Budget $budget): Response
    {
        $monthStart = Carbon::now()->startOfMonth();
        $thisMonth = fn (): Builder => AiCall::query()->where('created_at', '>=', $monthStart);

        $byFeature = $thisMonth()
            ->selectRaw('feature, count(*) as calls, sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens, sum(cost_micros) as cost_micros')
            ->groupBy('feature')
            ->get()
            ->keyBy(fn (AiCall $row): string => $row->feature->value);

        // Every feature gets a row, also without calls this month.
        $features = collect(AiFeature::cases())->map(fn (AiFeature $feature): array => [
            'key' => $feature->value,
            'label' => $feature->label(),
            ...$this->totals($byFeature->get($feature->value)),
        ])->all();

        $models = $thisMonth()
            ->selectRaw('model, min(priced) as priced, count(*) as calls, sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens, sum(cost_micros) as cost_micros')
            ->groupBy('model')
            ->orderByDesc('cost_micros')
            ->orderBy('model')
            ->get()
            ->map(fn (AiCall $row): array => [
                'model' => $row->model,
                // One unpriced call makes the model row "unpriced".
                'priced' => (bool) $row->getAttribute('priced'),
                ...$this->totals($row),
            ])->all();

        // The start of the day is in the app's timezone, the same as created_at.
        $days = AiCall::query()
            ->where('created_at', '>=', Carbon::now()->subDays(29)->startOfDay())
            ->selectRaw('date(created_at) as day, count(*) as calls, sum(cost_micros) as cost_micros')
            ->groupBy('day')
            ->orderByDesc('day')
            ->get()
            ->map(fn (AiCall $row): array => [
                'date' => (string) $row->getAttribute('day'),
                'calls' => (int) $row->getAttribute('calls'),
                'cost_micros' => (int) $row->cost_micros,
            ])->all();

        return Inertia::render('admin/Usage', [
            'month' => Carbon::now()->format('F Y'),
            'spent_micros' => $budget->spentThisMonthMicros(),
            'limit_micros' => $budget->limitMicros(),
            'remaining_micros' => $budget->remainingMicros(),
            'features' => $features,
            'models' => $models,
            'days' => $days,
            'has_calls' => AiCall::query()->exists(),
            'prices_checked_on' => (string) config('sprintlab.ai.prices_checked_on'),
            // Without SSH this is where the owner sees whether a new version arrived.
            'deployment' => DeployStatus::steps(),
        ]);
    }

    /**
     * @return array{calls: int, input_tokens: int, output_tokens: int, cost_micros: int}
     */
    private function totals(?AiCall $row): array
    {
        return [
            'calls' => (int) $row?->getAttribute('calls'),
            'input_tokens' => (int) $row?->input_tokens,
            'output_tokens' => (int) $row?->output_tokens,
            'cost_micros' => (int) $row?->cost_micros,
        ];
    }
}
