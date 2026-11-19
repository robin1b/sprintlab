<?php

namespace App\Models;

use App\Enums\AiFeature;
use Database\Factories\AiCallFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One call to the AI model and what it cost.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $sprint_id
 * @property AiFeature $feature
 * @property string $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $cost_micros Millionths of a US dollar.
 * @property bool $priced
 * @property Carbon|null $created_at
 * @property-read User|null $user
 * @property-read Sprint|null $sprint
 */
#[Fillable(['user_id', 'sprint_id', 'feature', 'model', 'input_tokens', 'output_tokens', 'cost_micros', 'priced'])]
class AiCall extends Model
{
    /** @use HasFactory<AiCallFactory> */
    use HasFactory;

    // Only a creation time: a call never changes.
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'feature' => AiFeature::class,
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cost_micros' => 'integer',
            'priced' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Sprint, $this>
     */
    public function sprint(): BelongsTo
    {
        return $this->belongsTo(Sprint::class);
    }
}
