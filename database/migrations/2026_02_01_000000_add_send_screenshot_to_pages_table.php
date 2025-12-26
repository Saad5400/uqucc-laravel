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
        Schema::table('pages', function (Blueprint $table) {
            if (! Schema::hasColumn('pages', 'quick_response_send_screenshot')) {
                $table->boolean('quick_response_send_screenshot')->default(false)->after('quick_response_send_link');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            if (Schema::hasColumn('pages', 'quick_response_send_screenshot')) {
                $table->dropColumn('quick_response_send_screenshot');
            }
        });
    }
};
