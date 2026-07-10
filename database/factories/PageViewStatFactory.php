<?php

namespace Database\Factories;

use App\Models\Page;
use App\Models\PageViewStat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PageViewStat>
 */
class PageViewStatFactory extends Factory
{
    protected $model = PageViewStat::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'page_id' => Page::factory(),
            'user_id' => null,
            'ip_address' => fake()->unique()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'view_count' => fake()->numberBetween(1, 50),
            'last_viewed_at' => now(),
        ];
    }
}
