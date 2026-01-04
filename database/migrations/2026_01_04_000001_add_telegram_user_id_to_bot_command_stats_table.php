<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_command_stats', function (Blueprint $table) {
            $table->bigInteger('telegram_user_id')->nullable()->after('user_id');
            $table->index('telegram_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('bot_command_stats', function (Blueprint $table) {
            $table->dropIndex(['telegram_user_id']);
            $table->dropColumn('telegram_user_id');
        });
    }
};
