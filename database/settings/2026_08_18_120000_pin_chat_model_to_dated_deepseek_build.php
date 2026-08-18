<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Move the pinned chat model onto the dated DeepSeek V4 Flash build.
     *
     * `AiSettings->chat_model` is what the assistants actually send —
     * config('ai.chat.model') is only the fallback for an empty setting — so
     * bumping the config alone would leave every environment on the old
     * rolling slug. Only rows still on that slug are rewritten; a model an
     * operator chose deliberately is left alone.
     */
    public function up(): void
    {
        $this->migrator->update(
            'ai.chat_model',
            fn (string $model): string => $model === 'deepseek/deepseek-v4-flash'
                ? 'deepseek/deepseek-v4-flash-0731'
                : $model,
        );
    }
};
