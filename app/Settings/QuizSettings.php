<?php

namespace App\Settings;

use App\Services\Telegram\ChatTarget;
use Spatie\LaravelSettings\Settings;

class QuizSettings extends Settings
{
    public bool $enabled;

    /**
     * Whether the bot posts periodic "answer the question of the day"
     * reminders while a quiz is live (see {@see \App\Services\Quiz\QuizReminder}).
     */
    public bool $reminders_enabled;

    /**
     * Where the daily quiz is posted. Each entry is a Telegram chat id
     * (negative for groups), optionally with a forum topic as «chat_id:thread_id»
     * for groups that use Telegram topics. One shared quiz and one shared
     * leaderboard across all of them — a member's first vote in any group is
     * the one that counts.
     *
     * @var array<int, string>
     */
    public array $chat_ids;

    /**
     * The time of day the question goes out, as «HH:MM» in the app timezone.
     * A single day can override it — see {@see \App\Services\Quiz\QuizSchedule}.
     */
    public string $post_time;

    public static function group(): string
    {
        return 'quiz';
    }

    /**
     * The quiz can only run with the feature on and at least one target
     * group configured.
     */
    public function isConfigured(): bool
    {
        return $this->enabled && $this->chat_ids !== [];
    }

    /**
     * The configured destinations as parsed chat/topic targets.
     *
     * @return array<int, ChatTarget>
     */
    public function targets(): array
    {
        return ChatTarget::parseAll($this->chat_ids);
    }
}
