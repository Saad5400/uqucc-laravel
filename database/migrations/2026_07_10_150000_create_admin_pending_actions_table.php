<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_pending_actions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('type');
            $table->json('payload');
            $table->text('summary');
            $table->string('status')->default('pending')->index();
            $table->foreignId('proposed_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('executed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_pending_actions');
    }
};
