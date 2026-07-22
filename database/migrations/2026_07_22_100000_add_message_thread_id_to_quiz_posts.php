<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_posts', function (Blueprint $table) {
            $table->bigInteger('message_thread_id')->nullable()->after('message_id');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_posts', function (Blueprint $table) {
            $table->dropColumn('message_thread_id');
        });
    }
};
