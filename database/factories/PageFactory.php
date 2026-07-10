<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Page>
 */
class PageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => '/'.fake()->unique()->slug(3),
            'title' => fake()->sentence(3),
            'html_content' => [
                'type' => 'doc',
                'content' => [
                    [
                        'type' => 'paragraph',
                        'content' => [
                            ['type' => 'text', 'text' => fake()->paragraph()],
                        ],
                    ],
                ],
            ],
            'hidden' => false,
            'hidden_from_bot' => false,
            'smart_search' => false,
            'requires_prefix' => false,
            'parent_id' => null,
            'level' => 0,
            'extension' => 'md',
        ];
    }

    /**
     * Indicate that the page is hidden from the public website.
     */
    public function hidden(): static
    {
        return $this->state(fn (array $attributes) => [
            'hidden' => true,
        ]);
    }

    /**
     * Indicate that the page is hidden from the Telegram bot.
     */
    public function hiddenFromBot(): static
    {
        return $this->state(fn (array $attributes) => [
            'hidden_from_bot' => true,
        ]);
    }

    /**
     * Indicate that the page is a child of the given page.
     */
    public function childOf(\App\Models\Page $parent): static
    {
        return $this->state(fn (array $attributes) => [
            'parent_id' => $parent->id,
            'level' => $parent->level + 1,
        ]);
    }
}
