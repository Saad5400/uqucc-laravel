<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Periodic "answer the question of the day" reminders, on by default. The
     * schedule fires two conditional nudges per live quiz; this switch turns
     * the whole thing off without a deploy.
     */
    public function up(): void
    {
        $this->migrator->add('quiz.reminders_enabled', true);
    }

    public function down(): void
    {
        $this->migrator->delete('quiz.reminders_enabled');
    }
};
