<?php

use App\Models\StudentGroup\Cohort;
use Illuminate\Database\Migrations\Migration;

/**
 * Add the university-id check to every intake's join checklist.
 *
 * The first three digits of a UQU student number encode the intake, so they are
 * what actually proves which دفعة someone belongs to — the rest of the
 * checklist proves who they are, not which batch.
 *
 * Separate from the import migration because that one has already run wherever
 * this feature is live: editing it there would change nothing. This appends to
 * whatever each intake currently lists, skipping any that already say it, so it
 * is safe on a fresh database (where the import already included the line) and
 * on one an admin has since edited by hand.
 */
return new class extends Migration
{
    private const REQUIREMENT = 'أول ٣ أرقام من الرقم الجامعي ظاهرة في الصورة';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Cohort::query()->each(function (Cohort $cohort): void {
            $requirements = $cohort->requirements ?? [];

            if (in_array(self::REQUIREMENT, $requirements, true)) {
                return;
            }

            $cohort->update(['requirements' => [...$requirements, self::REQUIREMENT]]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Cohort::query()->each(function (Cohort $cohort): void {
            $requirements = $cohort->requirements ?? [];

            $cohort->update([
                'requirements' => array_values(array_filter($requirements, fn (string $line) => $line !== self::REQUIREMENT)),
            ]);
        });
    }
};
