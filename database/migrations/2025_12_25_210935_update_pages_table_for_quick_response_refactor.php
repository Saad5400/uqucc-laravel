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
            // Remove old quick_response_enabled column (always active now)
            if (Schema::hasColumn('pages', 'quick_response_enabled')) {
                $table->dropColumn('quick_response_enabled');
            }

            // Add new auto-extract and customization toggles
            if (!Schema::hasColumn('pages', 'quick_response_auto_extract')) {
                $table->boolean('quick_response_auto_extract')->default(false)->after('extension');
            }
            if (!Schema::hasColumn('pages', 'quick_response_customize_message')) {
                $table->boolean('quick_response_customize_message')->default(false)->after('quick_response_auto_extract');
            }
            if (!Schema::hasColumn('pages', 'quick_response_customize_buttons')) {
                $table->boolean('quick_response_customize_buttons')->default(false)->after('quick_response_customize_message');
            }
            if (!Schema::hasColumn('pages', 'quick_response_customize_attachments')) {
                $table->boolean('quick_response_customize_attachments')->default(false)->after('quick_response_customize_buttons');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            // Restore quick_response_enabled
            if (!Schema::hasColumn('pages', 'quick_response_enabled')) {
                $table->boolean('quick_response_enabled')->default(false)->after('extension');
            }

            // Remove new columns
            $table->dropColumn([
                'quick_response_auto_extract',
                'quick_response_customize_message',
                'quick_response_customize_buttons',
                'quick_response_customize_attachments',
            ]);
        });
    }
};
