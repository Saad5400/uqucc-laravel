<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * The /manage assistant (confirm-gated AI control over pages and
     * settings) ships dark: OFF until an operator flips it on.
     */
    public function up(): void
    {
        $this->migrator->add('ai.admin_assistant_enabled', false);
    }
};
