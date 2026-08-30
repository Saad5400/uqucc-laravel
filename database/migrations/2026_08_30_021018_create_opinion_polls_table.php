<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opinion_polls', function (Blueprint $table) {
            $table->id();
            $table->date('poll_date')->unique();
            $table->text('question');
            $table->json('options');
            $table->string('status')->default('ready');
            $table->string('post_time', 5)->nullable();
            $table->json('results')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opinion_polls');
    }
};
