<?php

namespace App\Services\OpinionPoll;

use App\Models\OpinionPoll;
use App\Settings\OpinionPollSettings;
use Carbon\CarbonImmutable;

/**
 * When the day's opinion poll goes out.
 *
 * Same shape as the quiz's schedule and for the same reason: the hour is a
 * setting, and a single day may override it once ({@see OpinionPoll::$post_time})
 * without disturbing the default — so `poll:post` runs every minute and asks
 * this service whether the moment has come.
 */
class OpinionPollSchedule
{
    /**
     * Used when no posting time is configured at all. Evening on purpose: the
     * question of the day owns the afternoon, and two polls landing together
     * would split the same attention instead of adding to it.
     */
    public const DEFAULT_POST_TIME = '20:00';

    public function __construct(private readonly OpinionPollSettings $settings) {}

    /**
     * The time of day the given poll goes out, as «HH:MM» — its own one-off
     * time when an admin moved it, otherwise the default.
     */
    public function postTimeFor(?OpinionPoll $poll): string
    {
        $time = $poll?->post_time ?? $this->settings->post_time;

        return filled($time) ? $time : self::DEFAULT_POST_TIME;
    }

    /** The exact moment the given poll is meant to go out. */
    public function postsAt(OpinionPoll $poll): CarbonImmutable
    {
        return CarbonImmutable::parse($poll->poll_date)->setTimeFromTimeString($this->postTimeFor($poll));
    }

    /** The moment today's poll goes out. */
    public function todayPostsAt(): CarbonImmutable
    {
        return CarbonImmutable::parse(today())
            ->setTimeFromTimeString($this->postTimeFor(OpinionPoll::forDate(today())));
    }

    /** Whether today's posting moment has arrived. */
    public function isTodayDue(): bool
    {
        return ! $this->todayPostsAt()->isFuture();
    }
}
