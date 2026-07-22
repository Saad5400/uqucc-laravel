<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class QuizSettings extends Settings
{
    public bool $enabled;

    /**
     * Telegram chat ids of the groups the daily quiz is posted to (negative
     * for groups). One shared quiz and one shared leaderboard across all of
     * them — a member's first vote in any group is the one that counts.
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
}
