<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class AiSettings extends Settings
{
    public bool $ai_enabled;

    public bool $search_enabled;

    public bool $assistant_enabled;

    public bool $telegram_ai_enabled;

    public bool $admin_copilot_enabled;

    public bool $admin_assistant_enabled;

    public string $chat_model;

    public string $vision_model;

    public string $embedding_model;

    public float $daily_budget_usd;

    public int $per_session_rate_limit;

    public int $per_conversation_rate_limit;

    public static function group(): string
    {
        return 'ai';
    }

    /**
     * Check if a specific AI feature is enabled, honoring the master kill switch.
     */
    public function isFeatureEnabled(string $feature): bool
    {
        if (! $this->ai_enabled) {
            return false;
        }

        return match ($feature) {
            'search' => $this->search_enabled,
            'assistant' => $this->assistant_enabled,
            'telegram' => $this->telegram_ai_enabled,
            'admin_copilot' => $this->admin_copilot_enabled,
            'admin_assistant' => $this->admin_assistant_enabled,
            default => false,
        };
    }
}
