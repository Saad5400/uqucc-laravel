<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One player's vote on one daily quiz — the immutable audit trail behind the
 * denormalized {@see QuizPlayer} aggregates. Unique per (quiz, player):
 * Telegram quiz polls do not allow changing a vote.
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
}
