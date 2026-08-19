<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * The group's vote on what tomorrow's question should be about: a plain
 * Telegram poll listing four topics the current cycle has not covered yet,
 * posted right under the day's question and tallied when the next question is
 * generated ({@see \App\Services\Quiz\QuizTopicVote}).
 *
 * The illusion is only in the framing — every topic on a ballot is one the
 * cycle would have reached anyway, so voting decides the order, never whether
 * a topic gets its turn.
 */
class QuizTopicPoll extends Model
{
    /** @use HasFactory<\Database\Factories\QuizTopicPollFactory> */
    use HasFactory;

    protected $fillable = [
        'quiz_date',
        'topic_ids',
        'posts',
        'quiz_topic_id',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'quiz_date' => 'date',
            'topic_ids' => 'array',
            'posts' => 'array',
            'closed_at' => 'datetime',
        ];
    }

    /** The topic the vote settled on — null until the poll is tallied. */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(QuizTopic::class, 'quiz_topic_id');
    }

    public static function forDate(CarbonInterface $date): ?self
    {
        return static::query()->whereDate('quiz_date', $date)->first();
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    /**
     * The ballot as topic models, in the poll's own option order — so option N
     * maps back to a topic. A topic an admin deleted meanwhile becomes a null
     * in its slot rather than disappearing, because dropping it would shift
     * every later option onto the wrong topic.
     *
     * @return Collection<int, QuizTopic|null>
     */
    public function ballot(): Collection
    {
        $topics = QuizTopic::query()->findMany($this->topic_ids)->keyBy('id');

        return collect($this->topic_ids)
            ->map(fn (int $id): ?QuizTopic => $topics->get($id))
            ->values();
    }
}
