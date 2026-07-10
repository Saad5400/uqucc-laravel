<?php

namespace Database\Factories;

use App\Models\TelegramChatSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TelegramChatSetting>
 */
class TelegramChatSettingFactory extends Factory
{
    protected $model = TelegramChatSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chat_id' => fake()->unique()->numberBetween(1_000_000, 999_999_999),
            'ai_enabled' => false,
            'title' => fake()->words(2, true),
            'type' => 'private',
            'enabled_by' => null,
            'conversation_id' => null,
        ];
    }

    /**
     * A chat whose AI assistant is activated.
     */
    public function aiEnabled(): static
    {
        return $this->state(fn (array $attributes): array => [
            'ai_enabled' => true,
            'enabled_by' => (string) fake()->numberBetween(1_000_000, 999_999_999),
        ]);
    }

    /**
     * A Telegram group chat (negative chat id).
     */
    public function group(): static
    {
        return $this->state(fn (array $attributes): array => [
            'chat_id' => -fake()->unique()->numberBetween(1_000_000, 999_999_999),
            'type' => 'supergroup',
        ]);
    }
}
