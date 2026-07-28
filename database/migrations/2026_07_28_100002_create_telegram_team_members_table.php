<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('telegram_teams')->cascadeOnDelete();
            $table->bigInteger('telegram_user_id');
            $table->string('first_name')->nullable();
            $table->string('username')->nullable();
            $table->bigInteger('consent_message_id');
            $table->timestamp('consented_at');
            $table->bigInteger('added_by_telegram_id');
            $table->timestamps();

            $table->unique(['team_id', 'telegram_user_id']);
            $table->index('telegram_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_team_members');
    }
};
