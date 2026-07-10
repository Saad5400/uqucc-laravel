<?php

namespace Database\Factories\PrivateTutor;

use App\Models\PrivateTutor\PrivateTutorCourse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PrivateTutor\PrivateTutorCourse>
 */
class PrivateTutorCourseFactory extends Factory
{
    protected $model = PrivateTutorCourse::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
        ];
    }
}
