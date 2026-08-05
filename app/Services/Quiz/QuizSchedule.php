<?php

namespace App\Services\Quiz;

use App\Models\DailyQuiz;
use App\Settings\QuizSettings;
use Carbon\CarbonImmutable;

/**
 * When the daily question goes out.
 *
 * The time is a setting rather than a fixed schedule entry, and any single day
 * may override it once ({@see DailyQuiz::$post_time}) without disturbing the
 * default — so `quiz:post` runs every minute and asks this service whether the
 * moment has come, instead of being pinned to one hour at deploy time.
 */
class QuizSchedule
{
    /** Used when no posting time is configured at all. */
    public const DEFAULT_POST_TIME = '16:00';

    public function __construct(private readonly QuizSettings $settings) {}

    /**
     * The time of day the given question goes out, as «HH:MM» — its own
     * one-off time when an admin rescheduled it, otherwise the default.
     */
    public function postTimeFor(?DailyQuiz $quiz): string
    {
        $time = $quiz?->post_time ?? $this->settings->post_time;

        return filled($time) ? $time : self::DEFAULT_POST_TIME;
    }

    /** The exact moment the given question is meant to go out. */
    public function postsAt(DailyQuiz $quiz): CarbonImmutable
    {
        return CarbonImmutable::parse($quiz->quiz_date)->setTimeFromTimeString($this->postTimeFor($quiz));
    }

    /** The moment today's question goes out. */
    public function todayPostsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse(today())
            ->setTimeFromTimeString($this->postTimeFor(DailyQuiz::forDate(today())));
    }

    /** Whether today's posting moment has arrived. */
    public function isTodayDue(): bool
    {
        return ! $this->todayPostsAt()->isFuture();
    }
}
