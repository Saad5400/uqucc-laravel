<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('bot_command_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('command_name');
            $table->string('chat_type')->nullable(); // 'private', 'group', 'supergroup'
            $table->bigInteger('chat_id')->nullable();
            $table->integer('count')->default(1);
            $table->timestamp('last_used_at');
            $table->timestamps();

            $table->index(['user_id', 'command_name']);
            $table->index('command_name');
            $table->index('last_used_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bot_command_stats');
    }
};
