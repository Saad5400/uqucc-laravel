<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Telegram group member who has answered at least one daily quiz, keyed by
 * their raw Telegram user id (players are almost never panel users). Lifetime
 * and streak aggregates are denormalized here; the per-quiz audit trail lives
 * in {@see QuizAnswer}, and the weekly and rolling boards are summed from it
 * per quiz day rather than kept as counters here — see
 * {@see \App\Services\Quiz\QuizLeaderboard} for why.
 *
 * `major` is intentionally unused for now — kept so per-major leaderboards
 * stay possible later without a schema change.
 */
class QuizPlayer extends Model
{
    /** @use HasFactory<\Database\Factories\QuizPlayerFactory> */
    use HasFactory;

    protected $fillable = [
        'telegram_user_id',
        'first_name',
        'username',
        'major',
        'total_points',
        'current_streak',
        'best_streak',
        'streak_frozen_on',
        'correct_count',
        'answers_count',
        'last_answered_on',
    ];

    protected function casts(): array
    {
        return [
            'telegram_user_id' => 'integer',
            'total_points' => 'integer',
            'current_streak' => 'integer',
            'best_streak' => 'integer',
            'streak_frozen_on' => 'date',
            'correct_count' => 'integer',
            'answers_count' => 'integer',
            'last_answered_on' => 'date',
        ];
    }

    public function answers(): HasMany
    {
        return $this->hasMany(QuizAnswer::class);
    }

    public function displayName(): string
    {
        return trim((string) $this->first_name) !== ''
            ? trim((string) $this->first_name)
            : ($this->username !== null ? '@'.$this->username : 'مشارك');
    }
}
