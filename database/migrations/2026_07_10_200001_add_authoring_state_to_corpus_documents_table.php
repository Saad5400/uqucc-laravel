<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Page-authoring lifecycle on corpus documents: authoring_status is null
 * until the admin first triggers «توليد صفحة من المستند», then moves through
 * queued → running → done|failed (with authoring_error). authored_page_id
 * links to the unpublished draft page created when the AI decided the
 * document is NEW content; update decisions link through
 * page_content_proposals instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('corpus_documents', function (Blueprint $table) {
            $table->string('authoring_status')->nullable()->after('error');
            $table->text('authoring_error')->nullable()->after('authoring_status');
            $table->foreignId('authored_page_id')->nullable()->after('authoring_error')->constrained('pages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('corpus_documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('authored_page_id');
            $table->dropColumn(['authoring_status', 'authoring_error']);
        });
    }
};
