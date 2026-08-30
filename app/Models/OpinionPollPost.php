<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Telegram poll message for one {@see OpinionPoll} in one group. Telegram
 * gives every message its own poll, so each group's votes are counted here and
 * summed into the poll's own `results` when it closes.
 */
class OpinionPollPost extends Model
{
    /** @use HasFactory<\Database\Factories\OpinionPollPostFactory> */
    use HasFactory;

    protected $fillable = [
        'opinion_poll_id',
        'chat_id',
        'message_id',
        'message_thread_id',
        'telegram_poll_id',
        'votes',
        'posted_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'chat_id' => 'integer',
            'message_id' => 'integer',
            'message_thread_id' => 'integer',
            'votes' => 'array',
            'posted_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function poll(): BelongsTo
    {
        return $this->belongsTo(OpinionPoll::class, 'opinion_poll_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('closed_at');
    }
}
