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
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('title');
            $table->longText('html_content');
            $table->integer('order')->default(0);
            $table->string('icon')->nullable();
            $table->boolean('hidden')->default(false);
            $table->foreignId('parent_id')->nullable()->constrained('pages')->onDelete('cascade');
            $table->integer('level')->default(0);
            $table->string('extension')->default('md');
            $table->boolean('quick_response_enabled')->default(false);
            $table->boolean('quick_response_send_link')->default(true);
            $table->text('quick_response_message')->nullable();
            $table->string('quick_response_button_label')->nullable();
            $table->string('quick_response_button_url')->nullable();
            $table->json('quick_response_attachments')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes for performance
            $table->index('slug');
            $table->index('parent_id');
            $table->index(['hidden', 'order']);
            $table->index('level');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
