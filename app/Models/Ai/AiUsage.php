<?php

namespace App\Models\Ai;

use Database\Factories\Ai\AiUsageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per paid AI call — the spend ledger's storage. `cost` is the exact
 * provider-reported USD cost captured by {@see \App\Ai\Gateway\ReasoningOpenRouterGateway}
 * (0 when the provider reported none, e.g. free-tier or faked calls); token
 * counts are recorded when the response carried them. Append-only: rows are
 * never updated, so there is no updated_at.
 *
 * @property int $id
 * @property string $feature
 * @property string $model
 * @property int|null $prompt_tokens
 * @property int|null $completion_tokens
 * @property float $cost
 * @property \Illuminate\Support\Carbon|null $created_at
 */
class AiUsage extends Model
{
    /** @use HasFactory<AiUsageFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $table = 'ai_usage';

    protected $fillable = [
        'feature',
        'model',
        'prompt_tokens',
        'completion_tokens',
        'cost',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'cost' => 'float',
        ];
    }

    protected static function newFactory(): AiUsageFactory
    {
        return AiUsageFactory::new();
    }
}
