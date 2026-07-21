<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\QuizTopic>
 */
class QuizTopicFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'أساسيات '.$this->faker->unique()->word(),
            'prompt_hint' => null,
            'is_spotlight' => false,
            'is_active' => true,
            'last_used_at' => null,
        ];
    }

    public function spotlight(): static
    {
        return $this->state(fn (): array => ['is_spotlight' => true]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
