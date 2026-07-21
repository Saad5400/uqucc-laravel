<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An admin-curated theme the daily quiz question is generated from.
 *
 * Regular topics are foundational/cross-major; topics flagged `is_spotlight`
 * are major-specific and only picked on the weekly spotlight day, so the
 * mixed audience meets them at a predictable, limited cadence.
 */
class QuizTopic extends Model
{
    /** @use HasFactory<\Database\Factories\QuizTopicFactory> */
    use HasFactory;

    /**
     * The weekday (Carbon constant) reserved for major-spotlight topics.
     * Wednesday sits mid academic week (Sun–Thu) for Saudi universities.
     */
    public const SPOTLIGHT_WEEKDAY = CarbonInterface::WEDNESDAY;

    protected $fillable = [
        'name',
        'prompt_hint',
        'is_spotlight',
        'is_active',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'is_spotlight' => 'boolean',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function quizzes(): HasMany
    {
        return $this->hasMany(DailyQuiz::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * The topic to generate from on the given day: least-recently-used among
     * spotlight topics on the spotlight weekday (falling back to regular
     * topics when none exist), least-recently-used regular topic otherwise.
     */
    public static function pickForDate(CarbonInterface $date): ?self
    {
        $spotlightDay = $date->dayOfWeek === self::SPOTLIGHT_WEEKDAY;

        $pick = fn (bool $spotlight): ?self => static::query()
            ->active()
            ->where('is_spotlight', $spotlight)
            ->orderByRaw('last_used_at is not null')
            ->orderBy('last_used_at')
            ->orderBy('id')
            ->first();

        return $spotlightDay
            ? ($pick(true) ?? $pick(false))
            : ($pick(false) ?? $pick(true));
    }
}
