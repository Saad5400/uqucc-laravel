<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class QuizSettings extends Settings
{
    public bool $enabled;

    /** Telegram chat id of the group the daily quiz is posted to (negative for groups). */
    public ?string $chat_id;

    public static function group(): string
    {
        return 'quiz';
    }

    /**
     * The quiz can only run with the feature on and a target group configured.
     */
    public function isConfigured(): bool
    {
        return $this->enabled && filled($this->chat_id);
    }
}
