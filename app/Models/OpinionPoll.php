<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One «استطلاع الرأي» — an anonymous poll with no right answer, keyed by the
 * day it is meant to go out. It exists for the members the daily question
 * never reaches: answering a quiz risks being wrong in front of ten thousand
 * people, and tapping an opinion risks nothing, so this is the cheapest first
 * step into the group's daily ritual.
 *
 * Lifecycle: `ready` (written, still editable) → `posted` (live in the
 * groups, one {@see OpinionPollPost} per group) → `closed` (votes stopped
 * once {@see self::$closes_at} passes, `results` holding the final per-option
 * counts summed across every group).
 *
 * Nothing here scores: the polls are anonymous on purpose, so no player, no
 * points and no streak can be derived from them.
 */
class OpinionPoll extends Model
{
    /** @use HasFactory<\Database\Factories\OpinionPollFactory> */
    use HasFactory;

    public const STATUS_READY = 'ready';

    public const STATUS_POSTED = 'posted';

    public const STATUS_CLOSED = 'closed';

    /** Telegram's caps on a poll message. */
    public const MAX_QUESTION_CHARS = 300;

    public const MAX_OPTION_CHARS = 100;

    public const MIN_OPTIONS = 2;

    public const MAX_OPTIONS = 10;

    protected $fillable = [
        'poll_date',
        'question',
        'options',
        'status',
        'post_time',
        'results',
        'posted_at',
        'closes_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'poll_date' => 'date',
            'options' => 'array',
            'results' => 'array',
            'posted_at' => 'datetime',
            'closes_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function posts(): HasMany
    {
        return $this->hasMany(OpinionPollPost::class);
    }

    public static function forDate(CarbonInterface $date): ?self
    {
        return static::query()->whereDate('poll_date', $date)->first();
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    /**
     * The final per-option vote counts, padded to the number of options so a
     * caller can always index them alongside {@see self::$options}. All zeros
     * while the poll is still open — Telegram only hands over the counts when
     * the poll is stopped.
     *
     * @return array<int, int>
     */
    public function tally(): array
    {
        $results = array_map(intval(...), array_values($this->results ?? []));

        return array_slice(array_pad($results, count($this->options ?? []), 0), 0, count($this->options ?? []));
    }

    /** How many members voted — the poll is single-choice, so votes are voters. */
    public function totalVotes(): int
    {
        return array_sum($this->tally());
    }
}
