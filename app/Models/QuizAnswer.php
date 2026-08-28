<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One player's vote on one daily quiz — the immutable audit trail the
 * leaderboards are summed from, and the record behind the denormalized
 * {@see QuizPlayer} aggregates. Unique per (quiz, player): Telegram quiz
 * polls do not allow changing a vote.
 *
 * `answered_at` is the audit timestamp — when the vote reached us. It is
 * deliberately not what the standings are sliced by; see
 * {@see self::scopeForQuizDates()}.
 */
class QuizAnswer extends Model
{
    /** @use HasFactory<\Database\Factories\QuizAnswerFactory> */
    use HasFactory;

    protected $fillable = [
        'daily_quiz_id',
        'quiz_player_id',
        'selected_option',
        'is_correct',
        'points',
        'streak_at_answer',
        'answered_at',
    ];

    protected function casts(): array
    {
        return [
            'selected_option' => 'integer',
            'is_correct' => 'boolean',
            'points' => 'integer',
            'streak_at_answer' => 'integer',
            'answered_at' => 'datetime',
        ];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(DailyQuiz::class, 'daily_quiz_id');
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(QuizPlayer::class, 'quiz_player_id');
    }

    /**
     * Answers to the questions of one stretch of quiz days, `$through`
     * included; pass null for an open-ended period.
     *
     * The period is measured on the question's own day, not on `answered_at`.
     * A question takes votes from 16:00 until the next one replaces it the
     * following afternoon, so the same question is answered on both sides of
     * midnight — filtering by the clock would put those answers in different
     * weeks and let a late vote outrank an early one. Every vote on a
     * question counts where the question does.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForQuizDates(Builder $query, CarbonInterface $from, ?CarbonInterface $through = null): Builder
    {
        return $query->whereHas('quiz', fn (Builder $quiz): Builder => $quiz
            ->whereDate('quiz_date', '>=', $from)
            ->when($through !== null, fn (Builder $dated): Builder => $dated->whereDate('quiz_date', '<=', $through)));
    }
}
