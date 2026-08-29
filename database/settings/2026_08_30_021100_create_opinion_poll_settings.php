<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * «استطلاع الرأي»: an anonymous, no-wrong-answer poll posted to the same
     * groups as the daily question, at its own hour so the two never compete.
     * Off until an admin picks the groups.
     */
    public function up(): void
    {
        $this->migrator->add('opinion_poll.enabled', false);
        $this->migrator->add('opinion_poll.chat_ids', []);
        $this->migrator->add('opinion_poll.post_time', '20:00');
        $this->migrator->add('opinion_poll.open_hours', 24);
    }

    public function down(): void
    {
        $this->migrator->delete('opinion_poll.enabled');
        $this->migrator->delete('opinion_poll.chat_ids');
        $this->migrator->delete('opinion_poll.post_time');
        $this->migrator->delete('opinion_poll.open_hours');
    }
};
