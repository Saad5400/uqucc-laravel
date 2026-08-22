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
        Schema::create('student_groups', function (Blueprint $table) {
            $table->id();
            // Null major = a global group; null branch = every branch.
            $table->string('major')->nullable();
            $table->string('branch')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('order')->default(0);
            $table->timestamps();

            $table->index('order');
            $table->index(['branch', 'major']);
        });

        /*
         * A group serves one or more intakes. The college publishes a single set
         * of programme groups for دفعة ٤٦ و٤٧ together, so pinning a group to one
         * intake would force that set to be duplicated and then kept in sync by
         * hand across two copies.
         */
        Schema::create('student_group_cohort', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_group_id')->constrained('student_groups')->cascadeOnDelete();
            $table->foreignId('student_cohort_id')->constrained('student_cohorts')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['student_group_id', 'student_cohort_id'], 'group_cohort_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_group_cohort');
        Schema::dropIfExists('student_groups');
    }
};
