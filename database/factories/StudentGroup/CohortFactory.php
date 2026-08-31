<?php

namespace Database\Factories\StudentGroup;

use App\Models\StudentGroup\Cohort;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StudentGroup\Cohort>
 */
class CohortFactory extends Factory
{
    protected $model = Cohort::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'دفعة '.fake()->unique()->numberBetween(40, 60),
            'description' => 'قروبات تيليجرام للدفعة، للتعاون والإفادة في الدراسة.',
            'note' => null,
            'requirements' => ['صورة من البوابة الأكاديمية', 'اسم التخصص', 'رقم الدفعة'],
            'is_active' => true,
            'is_featured' => false,
            'shows_major_groups' => true,
        ];
    }

    /** The intake the public page opens on. */
    public function featured(): static
    {
        return $this->state(fn (array $attributes) => ['is_featured' => true]);
    }

    /** An intake that is no longer published (a graduated batch). */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => ['is_active' => false]);
    }

    /** An intake joined through its general group alone, with the programme step off. */
    public function withoutMajorGroups(): static
    {
        return $this->state(fn (array $attributes) => ['shows_major_groups' => false]);
    }

    /** An intake with no join checklist. */
    public function withoutRequirements(): static
    {
        return $this->state(fn (array $attributes) => ['requirements' => null]);
    }
}
