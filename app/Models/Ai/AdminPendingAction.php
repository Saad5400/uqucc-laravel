<?php

namespace App\Models\Ai;

use App\Models\User;
use Database\Factories\Ai\AdminPendingActionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One change the admin assistant PROPOSED but never applied itself: the
 * two-phase write contract of the /manage assistant. A propose_* tool
 * persists the row as `pending`; a human pressing تأكيد runs it through
 * {@see \App\Ai\Admin\ProposalExecutor} (→ `confirmed`, or `failed` with the
 * error surfaced), and رفض marks it `rejected`. ULID keys because the ids
 * travel through model output and the chat client.
 *
 * @property string $id
 * @property string $type
 * @property array<string, mixed> $payload
 * @property string $summary
 * @property string $status
 * @property int $proposed_by
 * @property \Illuminate\Support\Carbon|null $executed_at
 * @property string|null $error
 */
class AdminPendingAction extends Model
{
    /** @use HasFactory<AdminPendingActionFactory> */
    use HasFactory, HasUlids;

    public const TYPE_PAGE_CHANGE = 'page_change';

    public const TYPE_SETTINGS_CHANGE = 'settings_change';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'type',
        'payload',
        'summary',
        'status',
        'proposed_by',
        'executed_at',
        'error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'executed_at' => 'datetime',
        ];
    }

    public function proposedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'proposed_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * The shape the chat client renders as an action card (SSE `proposal`
     * events and the rehydration endpoint agree on it).
     *
     * @return array{id: string, type: string, summary: string, payload: array<string, mixed>, status: string, error: string|null}
     */
    public function toClientPayload(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'summary' => $this->summary,
            'payload' => $this->payload,
            'status' => $this->status,
            'error' => $this->error,
        ];
    }
}
