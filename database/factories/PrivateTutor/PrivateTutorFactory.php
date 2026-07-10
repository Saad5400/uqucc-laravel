<?php

namespace Database\Factories\PrivateTutor;

use App\Models\PrivateTutor\PrivateTutor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PrivateTutor\PrivateTutor>
 */
class PrivateTutorFactory extends Factory
{
    protected $model = PrivateTutor::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'url' => fake()->url(),
        ];
    }

    /**
     * A tutor without a published link.
     */
    public function withoutUrl(): static
    {
        return $this->state(fn (array $attributes) => ['url' => null]);
    }
}
