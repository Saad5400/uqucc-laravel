<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An admin-curated theme the daily quiz question is generated from.
 *
 * Regular topics are foundational/cross-major; topics flagged `is_spotlight`
 * are major-specific and only picked on the weekly spotlight day, so the
 * mixed audience meets them at a predictable, limited cadence.
 *
 * Topics rotate in cycles: `cycle_used_at` is stamped when a topic supplies a
 * question and cleared for the whole pool only once every topic in it has had
 * its turn. So no topic can be skipped, however tomorrow's topic is chosen —
 * least-recently-used here, or the group's vote in
 * {@see \App\Services\Quiz\QuizTopicVote}, which only ever offers topics the
 * running cycle still owes.
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
        'cycle_used_at',
    ];

    protected function casts(): array
    {
        return [
            'is_spotlight' => 'boolean',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
            'cycle_used_at' => 'datetime',
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
     * Every active topic eligible on the given day, least-recently-used first:
     * the spotlight topics on the spotlight weekday and the regular ones
     * otherwise, each falling back to the other pool when its own is empty.
     *
     * @return Collection<int, self>
     */
    public static function poolForDate(CarbonInterface $date): Collection
    {
        $pool = fn (bool $spotlight): Collection => static::query()
            ->active()
            ->where('is_spotlight', $spotlight)
            ->orderByRaw('last_used_at is not null')
            ->orderBy('last_used_at')
            ->orderBy('id')
            ->get();

        $preferred = $pool($date->dayOfWeek === self::SPOTLIGHT_WEEKDAY);

        return $preferred->isNotEmpty()
            ? $preferred
            : $pool($date->dayOfWeek !== self::SPOTLIGHT_WEEKDAY);
    }

    /**
     * What the day's pool still owes the running cycle, least-recently-used
     * first. Exhausting the pool starts the next cycle right here — the only
     * place the marks are cleared, so "nothing left to cover" and "everything
     * is available again" can never disagree.
     *
     * @return Collection<int, self>
     */
    public static function cycleCandidates(CarbonInterface $date): Collection
    {
        $pool = static::poolForDate($date);
        $pending = $pool->filter(fn (self $topic): bool => $topic->cycle_used_at === null)->values();

        if ($pending->isNotEmpty() || $pool->isEmpty()) {
            return $pending;
        }

        static::query()->whereKey($pool->modelKeys())->update(['cycle_used_at' => null]);

        return $pool->each(fn (self $topic) => $topic->setAttribute('cycle_used_at', null))->values();
    }

    /**
     * The topic to generate from on the given day when nothing else decided
     * it: the least-recently-used one the cycle has not covered yet.
     */
    public static function pickForDate(CarbonInterface $date): ?self
    {
        return static::cycleCandidates($date)->first();
    }

    /** Record that this topic supplied a question, and spend its cycle turn. */
    public function markUsed(): void
    {
        $this->update(['last_used_at' => now(), 'cycle_used_at' => now()]);
    }
}
