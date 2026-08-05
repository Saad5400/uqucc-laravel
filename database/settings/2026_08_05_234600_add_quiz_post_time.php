<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * The time of day the question goes out, as «HH:MM». It used to be baked
     * into the schedule; making it a setting lets admins move it — and move a
     * single day on its own — without a deploy.
     */
    public function up(): void
    {
        $this->migrator->add('quiz.post_time', '16:00');
    }

    public function down(): void
    {
        $this->migrator->delete('quiz.post_time');
    }
};
