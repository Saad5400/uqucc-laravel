<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('ai.ai_enabled', false);
        $this->migrator->add('ai.search_enabled', false);
        $this->migrator->add('ai.assistant_enabled', false);
        $this->migrator->add('ai.telegram_ai_enabled', false);
        $this->migrator->add('ai.admin_copilot_enabled', false);
        $this->migrator->add('ai.chat_model', 'deepseek/deepseek-v4-flash');
        $this->migrator->add('ai.vision_model', 'google/gemini-2.5-flash');
        $this->migrator->add('ai.embedding_model', 'openai/text-embedding-3-small');
        $this->migrator->add('ai.daily_budget_usd', 5.0);
        $this->migrator->add('ai.per_session_rate_limit', 20);
        $this->migrator->add('ai.per_conversation_rate_limit', 30);
    }
};
