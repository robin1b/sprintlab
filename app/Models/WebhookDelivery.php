<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One webhook call from GitHub, kept so it can be processed (and retried) by a job.
 *
 * @property int $id
 * @property string $delivery_id
 * @property string $event
 * @property string|null $action
 * @property array<string, mixed> $payload
 * @property Carbon|null $processed_at
 * @property string|null $error
 */
#[Fillable(['delivery_id', 'event', 'action', 'payload', 'processed_at', 'error'])]
class WebhookDelivery extends Model
{
    use MassPrunable;

    /**
     * Deliveries older than the retention period are removed by `model:prune`.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', Carbon::now()->subDays((int) config('sprintlab.retention.webhook_days')));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
