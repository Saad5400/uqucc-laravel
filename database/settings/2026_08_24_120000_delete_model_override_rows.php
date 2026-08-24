<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Delete the three model rows that sat ABOVE config and silently won
     * (ai-kit docs/DECISIONS.md #26).
     *
     * This is the fix for a real incident, not tidying. `ai.chat_model` beat
     * `config('ai.chat.model')` and the kit's shared default, so a deploy could
     * move the configured chat model and change nothing — the app kept
     * answering students on the row's stale slug. That is exactly what
     * happened: the fleet moved to DeepSeek, the row still said Gemini Flash
     * Lite, and students kept getting the weaker replies.
     *
     * `ai.embedding_model` goes with them for a different reason: nothing ever
     * read it. Embeddings resolve `config('ai.embeddings.model')` directly, so
     * the row was decorative — an editable field in /manage that changed
     * nothing, which is its own kind of trap.
     *
     * Model choice now lives in config only, resolved in one place
     * ({@see \App\Ai\ModelRegistry}) and defaulting to the fleet's shared
     * values in the kit.
     */
    public function up(): void
    {
        $this->migrator->deleteIfExists('ai.chat_model');
        $this->migrator->deleteIfExists('ai.vision_model');
        $this->migrator->deleteIfExists('ai.embedding_model');
    }
};
