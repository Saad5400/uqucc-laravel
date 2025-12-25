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
            if (Schema::hasColumn('pages', 'description')) {
                $table->dropColumn('description');
            }

            if (Schema::hasColumn('pages', 'stem')) {
                $table->dropColumn('stem');
            }

            if (Schema::hasColumn('pages', 'og_image')) {
                $table->dropColumn('og_image');
            }

            if (!Schema::hasColumn('pages', 'quick_response_enabled')) {
                $table->boolean('quick_response_enabled')->default(false)->after('extension');
            }
            if (!Schema::hasColumn('pages', 'quick_response_send_link')) {
                $table->boolean('quick_response_send_link')->default(true)->after('quick_response_enabled');
            }
            if (!Schema::hasColumn('pages', 'quick_response_message')) {
                $table->text('quick_response_message')->nullable()->after('quick_response_send_link');
            }
            if (!Schema::hasColumn('pages', 'quick_response_buttons')) {
                $table->json('quick_response_buttons')->nullable()->after('quick_response_message');
            }
            if (!Schema::hasColumn('pages', 'quick_response_attachments')) {
                $table->json('quick_response_attachments')->nullable()->after('quick_response_buttons');
            }

            if (Schema::hasColumn('pages', 'quick_response_button_label')) {
                $table->dropColumn('quick_response_button_label');
            }

            if (Schema::hasColumn('pages', 'quick_response_button_url')) {
                $table->dropColumn('quick_response_button_url');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->text('description')->nullable()->after('title');
            $table->string('stem')->nullable()->after('level');
            $table->string('og_image')->nullable()->after('icon');

            $table->string('quick_response_button_label')->nullable()->after('quick_response_message');
            $table->string('quick_response_button_url')->nullable()->after('quick_response_button_label');

            $table->dropColumn([
                'quick_response_enabled',
                'quick_response_send_link',
                'quick_response_message',
                'quick_response_buttons',
                'quick_response_attachments',
            ]);
        });
    }
};
