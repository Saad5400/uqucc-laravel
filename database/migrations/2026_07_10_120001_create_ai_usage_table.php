<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AI spend ledger: one append-only row per paid AI call, carrying the
 * exact provider-reported USD cost. Summed per day it enforces the operator's
 * daily budget (see App\Ai\Spend\SpendLedger).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->string('feature', 50);
            $table->string('model');
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->decimal('cost', 12, 6)->default(0);
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
            $table->index(['feature', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
    }
};
