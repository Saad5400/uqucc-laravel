<?php

namespace Database\Factories;

use App\Models\Page;
use App\Models\PageChangeRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PageChangeRequest>
 */
class PageChangeRequestFactory extends Factory
{
    protected $model = PageChangeRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'page_id' => Page::factory(),
            'author_id' => User::factory(),
            'reviewed_by' => null,
            'payload' => ['html_content' => $this->faker->sentence()],
            'status' => PageChangeRequest::STATUS_PENDING,
            'review_note' => null,
            'reviewed_at' => null,
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PageChangeRequest::STATUS_APPROVED,
            'reviewed_by' => User::factory(),
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => PageChangeRequest::STATUS_REJECTED,
            'reviewed_by' => User::factory(),
            'reviewed_at' => now(),
        ]);
    }
}
