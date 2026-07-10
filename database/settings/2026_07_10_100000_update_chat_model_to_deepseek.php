<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Chat moved from Gemini Flash to DeepSeek V4 Flash (high reasoning via
     * OpenRouter — same setup as s-grade). Only rows still on the old default
     * are rewritten; an operator-customized model is left untouched.
     */
    public function up(): void
    {
        $this->migrator->update(
            'ai.chat_model',
            fn (string $model): string => $model === 'google/gemini-3.5-flash' ? 'deepseek/deepseek-v4-flash' : $model,
        );
    }
};
