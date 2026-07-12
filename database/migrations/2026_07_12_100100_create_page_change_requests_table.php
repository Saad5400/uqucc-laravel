<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A page edit a review-mode editor submitted for approval. The live page is
 * never touched until a reviewer approves the request; the validated partial
 * payload is replayed against the page verbatim on approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('payload');
            $table->string('status')->default('pending')->index();
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_change_requests');
    }
};
