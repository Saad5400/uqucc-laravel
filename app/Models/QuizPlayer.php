<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Telegram group member who has answered at least one daily quiz, keyed by
 * their raw Telegram user id (players are almost never panel users). Point
 * and streak aggregates are denormalized here for cheap leaderboards; the
 * per-quiz audit trail lives in {@see QuizAnswer}.
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
        'weekly_points',
        'current_streak',
        'best_streak',
        'correct_count',
        'answers_count',
        'last_answered_on',
    ];

    protected function casts(): array
    {
        return [
            'telegram_user_id' => 'integer',
            'total_points' => 'integer',
            'weekly_points' => 'integer',
            'current_streak' => 'integer',
            'best_streak' => 'integer',
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
