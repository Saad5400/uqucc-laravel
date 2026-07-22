<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One Telegram poll message for one daily quiz in one group. Each configured
 * group gets its own poll (Telegram assigns a distinct poll id per message),
 * all mapping back to the same {@see DailyQuiz} — so votes from every group
 * land on one shared quiz and one shared leaderboard.
 */
class QuizPost extends Model
{
    /** @use HasFactory<\Database\Factories\QuizPostFactory> */
    use HasFactory;

    protected $fillable = [
        'daily_quiz_id',
        'chat_id',
        'message_id',
        'telegram_poll_id',
        'posted_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'chat_id' => 'integer',
            'message_id' => 'integer',
            'posted_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(DailyQuiz::class, 'daily_quiz_id');
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
