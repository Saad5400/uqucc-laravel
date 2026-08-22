<?php

namespace Database\Factories\StudentGroup;

use App\Models\StudentGroup\Branch;
use App\Models\StudentGroup\Cohort;
use App\Models\StudentGroup\Major;
use App\Models\StudentGroup\StudentGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StudentGroup\StudentGroup>
 */
class StudentGroupFactory extends Factory
{
    protected $model = StudentGroup::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'student_cohort_id' => CohortFactory::new(),
            'major' => Major::ComputerScience,
            'branch' => Branch::Main,
            'is_active' => true,
        ];
    }

    /** The cohort's general group: no programme, every branch. */
    public function general(): static
    {
        return $this->state(fn (array $attributes) => ['major' => null, 'branch' => null]);
    }

    public function major(Major $major): static
    {
        return $this->state(fn (array $attributes) => ['major' => $major]);
    }

    public function branch(Branch $branch): static
    {
        return $this->state(fn (array $attributes) => ['branch' => $branch]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    /** Attach the group to an existing cohort. */
    public function forCohort(Cohort $cohort): static
    {
        return $this->state(fn (array $attributes) => ['student_cohort_id' => $cohort->id]);
    }
}
