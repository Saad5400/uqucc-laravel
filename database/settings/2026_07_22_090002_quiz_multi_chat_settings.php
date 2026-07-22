<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * The daily quiz can now target several groups: quiz.chat_id (single,
     * nullable) becomes quiz.chat_ids (array), carrying over a configured id.
     */
    public function up(): void
    {
        $chatId = null;

        if ($this->migrator->exists('quiz.chat_id')) {
            $this->migrator->update('quiz.chat_id', function (mixed $value) use (&$chatId): mixed {
                $chatId = $value;

                return $value;
            });

            $this->migrator->delete('quiz.chat_id');
        }

        $this->migrator->add('quiz.chat_ids', filled($chatId) ? [(string) $chatId] : []);
    }

    public function down(): void
    {
        $this->migrator->delete('quiz.chat_ids');
        $this->migrator->add('quiz.chat_id', null);
    }
};
