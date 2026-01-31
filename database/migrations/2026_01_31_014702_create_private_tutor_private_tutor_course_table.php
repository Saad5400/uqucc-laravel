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
        Schema::create('private_tutor_private_tutor_course', function (Blueprint $table) {
            $table->id();
            $table->foreignId('private_tutor_id')->constrained('private_tutors')->onDelete('cascade');
            $table->foreignId('private_tutor_course_id')->constrained('private_tutor_courses')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['private_tutor_id', 'private_tutor_course_id'], 'tutor_course_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('private_tutor_private_tutor_course');
    }
};
