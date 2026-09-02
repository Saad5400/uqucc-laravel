<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bot no longer sends a page as a picture: a reply is the page's content
 * as text in a collapsed quote, so the per-page "send a screenshot" switch has
 * nothing left to switch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn('quick_response_send_screenshot');
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->boolean('quick_response_send_screenshot')->default(false)->after('quick_response_send_link');
        });
    }
};
