<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One AI-generated multiple-choice question, keyed by the day it is meant to
 * be posted. Lifecycle: `ready` (generated, admins may still edit) →
 * `posted` (live quiz polls in the configured groups — one {@see QuizPost}
 * per group) → `closed` (polls stopped when the next day's quiz goes out).
 */
class DailyQuiz extends Model
{
    /** @use HasFactory<\Database\Factories\DailyQuizFactory> */
    use HasFactory;

    public const STATUS_READY = 'ready';

    public const STATUS_POSTED = 'posted';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'quiz_topic_id',
        'quiz_date',
        'question',
        'options',
        'correct_option',
        'explanation',
        'hint',
        'status',
        'posted_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'quiz_date' => 'date',
            'options' => 'array',
            'correct_option' => 'integer',
            'posted_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(QuizTopic::class, 'quiz_topic_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(QuizAnswer::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(QuizPost::class);
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public static function forDate(CarbonInterface $date): ?self
    {
        return static::query()->whereDate('quiz_date', $date)->first();
    }

    /**
     * Resolve a Telegram poll id to its quiz. Every group's poll has its own
     * id, so the lookup goes through the per-group posts.
     */
    public static function findByPollId(string $pollId): ?self
    {
        return QuizPost::query()->where('telegram_poll_id', $pollId)->first()?->quiz;
    }

    /**
     * The most recent quiz that went out before the given date — the anchor
     * for streak continuation (a streak survives days where no quiz was
     * posted at all, e.g. a generation outage).
     */
    public static function lastPostedBefore(CarbonInterface $date): ?self
    {
        return static::query()
            ->whereIn('status', [self::STATUS_POSTED, self::STATUS_CLOSED])
            ->whereDate('quiz_date', '<', $date)
            ->orderByDesc('quiz_date')
            ->first();
    }
}
