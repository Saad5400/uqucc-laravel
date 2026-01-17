<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('telegram.page_management_allowed_chat_ids', []);
        $this->migrator->add('telegram.page_management_auto_delete_messages', true);
    }
};
