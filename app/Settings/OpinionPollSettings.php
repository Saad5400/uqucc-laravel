<?php

namespace App\Settings;

use App\Services\Telegram\ChatTarget;
use Spatie\LaravelSettings\Settings;

class OpinionPollSettings extends Settings
{
    public bool $enabled;

    /**
     * Where the opinion poll is posted — same format as the quiz's targets:
     * a Telegram chat id, optionally «chat_id:thread_id» for a group that uses
     * forum topics. Deliberately its own list rather than the quiz's: the poll
     * is the chattier ritual and may belong in a different room.
     *
     * @var array<int, string>
     */
    public array $chat_ids;

    /** The time of day the poll goes out, as «HH:MM» in the app timezone. */
    public string $post_time;

    /**
     * How long a poll keeps taking votes before it is stopped and its result
     * announced. A whole day by default, so a member who reads the group once
     * every evening never misses one.
     */
    public int $open_hours;

    public static function group(): string
    {
        return 'opinion_poll';
    }

    /**
     * The poll can only run with the feature on and at least one target group
     * configured.
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
