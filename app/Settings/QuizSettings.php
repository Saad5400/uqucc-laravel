<?php

namespace App\Settings;

use App\Services\Quiz\QuizTarget;
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
     * @return array<int, QuizTarget>
     */
    public function targets(): array
    {
        return array_map(QuizTarget::parse(...), array_values($this->chat_ids));
    }
}
