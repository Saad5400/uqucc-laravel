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
        Schema::dropIfExists('page_search_cache');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('page_search_cache', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->string('section_id')->unique();
            $table->string('title');
            $table->text('content');
            $table->integer('level');
            $table->integer('position');
            $table->timestamps();

            $table->index('page_id');
        });
    }
};
