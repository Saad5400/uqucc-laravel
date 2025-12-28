<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add new individual auto extract columns
        Schema::table('pages', function (Blueprint $table) {
            $table->boolean('quick_response_auto_extract_message')->default(false)->after('quick_response_auto_extract');
            $table->boolean('quick_response_auto_extract_buttons')->default(false)->after('quick_response_auto_extract_message');
            $table->boolean('quick_response_auto_extract_attachments')->default(false)->after('quick_response_auto_extract_buttons');
        });

        // Migrate existing data
        // Logic:
        // - If auto_extract is TRUE and customize_X is FALSE → auto_extract_X = TRUE
        // - If auto_extract is TRUE and customize_X is TRUE → auto_extract_X = FALSE
        // - If auto_extract is FALSE → auto_extract_X = FALSE
        DB::table('pages')->get()->each(function ($page) {
            $autoExtract = $page->quick_response_auto_extract;
            $customizeMessage = $page->quick_response_customize_message;
            $customizeButtons = $page->quick_response_customize_buttons;
            $customizeAttachments = $page->quick_response_customize_attachments;

            DB::table('pages')
                ->where('id', $page->id)
                ->update([
                    'quick_response_auto_extract_message' => $autoExtract && ! $customizeMessage,
                    'quick_response_auto_extract_buttons' => $autoExtract && ! $customizeButtons,
                    'quick_response_auto_extract_attachments' => $autoExtract && ! $customizeAttachments,
                ]);
        });

        // Drop old columns
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn([
                'quick_response_auto_extract',
                'quick_response_customize_message',
                'quick_response_customize_buttons',
                'quick_response_customize_attachments',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Add back old columns
        Schema::table('pages', function (Blueprint $table) {
            $table->boolean('quick_response_auto_extract')->default(false)->after('extension');
            $table->boolean('quick_response_customize_message')->default(false)->after('quick_response_auto_extract');
            $table->boolean('quick_response_customize_buttons')->default(false)->after('quick_response_customize_message');
            $table->boolean('quick_response_customize_attachments')->default(false)->after('quick_response_customize_buttons');
        });

        // Migrate data back
        // Logic (reverse):
        // - If any auto_extract_X is TRUE → auto_extract = TRUE
        // - If auto_extract_X is FALSE and has custom data → customize_X = TRUE
        DB::table('pages')->get()->each(function ($page) {
            $autoExtractMessage = $page->quick_response_auto_extract_message;
            $autoExtractButtons = $page->quick_response_auto_extract_buttons;
            $autoExtractAttachments = $page->quick_response_auto_extract_attachments;

            $hasAutoExtract = $autoExtractMessage || $autoExtractButtons || $autoExtractAttachments;

            DB::table('pages')
                ->where('id', $page->id)
                ->update([
                    'quick_response_auto_extract' => $hasAutoExtract,
                    'quick_response_customize_message' => ! $autoExtractMessage && ! empty($page->quick_response_message),
                    'quick_response_customize_buttons' => ! $autoExtractButtons && ! empty($page->quick_response_buttons),
                    'quick_response_customize_attachments' => ! $autoExtractAttachments && ! empty($page->quick_response_attachments),
                ]);
        });

        // Drop new columns
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn([
                'quick_response_auto_extract_message',
                'quick_response_auto_extract_buttons',
                'quick_response_auto_extract_attachments',
            ]);
        });
    }
};
