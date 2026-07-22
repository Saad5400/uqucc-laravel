<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A quiz can now be posted to several groups, so the per-post Telegram
     * identifiers move from daily_quizzes into quiz_posts rows. Any already
     * posted quiz is carried over so its votes keep scoring.
     */
    public function up(): void
    {
        DB::table('daily_quizzes')
            ->whereNotNull('telegram_poll_id')
            ->orderBy('id')
            ->each(function (object $quiz): void {
                DB::table('quiz_posts')->insert([
                    'daily_quiz_id' => $quiz->id,
                    'chat_id' => $quiz->chat_id,
                    'message_id' => $quiz->message_id,
                    'telegram_poll_id' => $quiz->telegram_poll_id,
                    'posted_at' => $quiz->posted_at ?? now(),
                    'closed_at' => $quiz->closed_at,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        Schema::table('daily_quizzes', function (Blueprint $table) {
            $table->dropUnique(['telegram_poll_id']);
            $table->dropColumn(['telegram_poll_id', 'chat_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::table('daily_quizzes', function (Blueprint $table) {
            $table->string('telegram_poll_id')->nullable()->unique();
            $table->bigInteger('chat_id')->nullable();
            $table->bigInteger('message_id')->nullable();
        });
    }
};
