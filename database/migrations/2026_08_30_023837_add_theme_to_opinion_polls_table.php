<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which angle a poll asks from ({@see App\Ai\OpinionPoll\OpinionPollTheme}).
     * Null on hand-written polls, which nobody has to categorize; the author
     * rotates the themes so a generated queue does not turn into a month of
     * questions about editors.
     */
    public function up(): void
    {
        Schema::table('opinion_polls', function (Blueprint $table) {
            $table->string('theme')->nullable()->after('options');
        });
    }

    public function down(): void
    {
        Schema::table('opinion_polls', function (Blueprint $table) {
            $table->dropColumn('theme');
        });
    }
};
