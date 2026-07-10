<?php

namespace Database\Factories\Ai;

use App\Models\Ai\AiUsage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiUsage>
 */
class AiUsageFactory extends Factory
{
    protected $model = AiUsage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'feature' => 'assistant',
            'model' => 'google/gemini-3.5-flash',
            'prompt_tokens' => fake()->numberBetween(100, 5_000),
            'completion_tokens' => fake()->numberBetween(50, 1_500),
            'cost' => fake()->randomFloat(6, 0.0001, 0.05),
        ];
    }
}
