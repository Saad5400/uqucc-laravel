<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Defaults to true so every existing intake keeps publishing its programme
     * groups exactly as before.
     */
    public function up(): void
    {
        Schema::table('student_cohorts', function (Blueprint $table) {
            $table->boolean('shows_major_groups')->default(true)->after('is_featured');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('student_cohorts', function (Blueprint $table) {
            $table->dropColumn('shows_major_groups');
        });
    }
};
