<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_quizzes', function (Blueprint $table) {
            $table->text('body')->nullable()->after('question');
        });
    }

    public function down(): void
    {
        Schema::table('daily_quizzes', function (Blueprint $table) {
            $table->dropColumn('body');
        });
    }
};
