<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('quiz.enabled', false);
        $this->migrator->add('quiz.chat_id', null);
    }

    public function down(): void
    {
        $this->migrator->delete('quiz.enabled');
        $this->migrator->delete('quiz.chat_id');
    }
};
