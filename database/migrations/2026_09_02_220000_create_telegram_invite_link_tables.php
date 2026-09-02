<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_invite_links', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('chat_id')->index();
            $table->string('chat_title')->nullable();
            $table->string('invite_link')->unique();
            $table->string('link_name')->nullable();
            $table->bigInteger('creator_telegram_user_id')->index();
            $table->string('creator_username')->nullable();
            $table->string('creator_name')->nullable();
            $table->foreignId('creator_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('member_limit')->nullable();
            $table->unsignedInteger('joins_count')->default(0);
            $table->timestamps();

            $table->index(['chat_id', 'creator_telegram_user_id']);
        });

        Schema::create('telegram_invite_link_joins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('telegram_invite_link_id')->nullable()->constrained('telegram_invite_links')->nullOnDelete();
            $table->bigInteger('chat_id')->index();
            $table->string('invite_link')->nullable();
            $table->bigInteger('creator_telegram_user_id')->nullable()->index();
            $table->bigInteger('joiner_telegram_user_id')->index();
            $table->string('joiner_username')->nullable();
            $table->string('joiner_name')->nullable();
            $table->string('source')->default('invite_link');
            $table->timestamp('joined_at')->index();
            $table->timestamps();

            $table->unique(['chat_id', 'joiner_telegram_user_id', 'joined_at'], 'telegram_join_unique_event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_invite_link_joins');
        Schema::dropIfExists('telegram_invite_links');
    }
};
