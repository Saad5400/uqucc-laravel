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
        Schema::create('group_supervisors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_group_id')->constrained('student_groups')->cascadeOnDelete();
            $table->string('name');
            // At least one contact is required; which one is enforced in validation.
            $table->string('telegram_username')->nullable();
            $table->string('whatsapp_number')->nullable();
            $table->string('section');
            $table->boolean('is_available')->default(true);
            $table->integer('order')->default(0);
            $table->timestamps();

            $table->index(['student_group_id', 'section', 'order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('group_supervisors');
    }
};
