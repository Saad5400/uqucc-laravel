<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Move the pinned chat model onto the fleet's shared default, Google
     * Gemini Flash Lite (ai-kit docs/DECISIONS.md #21).
     *
     * `AiSettings->chat_model` is what the assistants actually send —
     * config is only the fallback for an empty row — so inheriting the kit's
     * shared default in config alone would leave every environment on the old
     * DeepSeek slug. Only rows still on that slug are rewritten; a model an
     * operator chose deliberately is left alone.
     *
     * `reasoning_effort` stays 'low': the shared default supports it, so the
     * interactive surface keeps the faster path.
     */
    public function up(): void
    {
        $this->migrator->update(
            'ai.chat_model',
            fn (string $model): string => $model === 'deepseek/deepseek-v4-flash-0731'
                ? 'google/gemini-3.5-flash-lite'
                : $model,
        );
    }
};
