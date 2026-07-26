<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_quizzes', function (Blueprint $table) {
            $table->text('obvious_hint')->nullable()->after('hint');
        });
    }

    public function down(): void
    {
        Schema::table('daily_quizzes', function (Blueprint $table) {
            $table->dropColumn('obvious_hint');
        });
    }
};
