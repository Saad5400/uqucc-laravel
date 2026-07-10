<?php

namespace Database\Factories\Ai;

use App\Models\Ai\AdminPendingAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdminPendingAction>
 */
class AdminPendingActionFactory extends Factory
{
    protected $model = AdminPendingAction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => AdminPendingAction::TYPE_PAGE_CHANGE,
            'payload' => ['action' => 'rename', 'page_id' => 1, 'title' => fake()->sentence(3)],
            'summary' => 'إعادة تسمية صفحة.',
            'status' => AdminPendingAction::STATUS_PENDING,
            'proposed_by' => User::factory(),
            'executed_at' => null,
            'error' => null,
        ];
    }

    public function settingsChange(string $group = 'ai', string $key = 'search_enabled', string $rawValue = 'true'): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => AdminPendingAction::TYPE_SETTINGS_CHANGE,
            'payload' => ['group' => $group, 'key' => $key, 'value' => $rawValue, 'raw_value' => $rawValue, 'old_value' => null],
            'summary' => "تغيير الإعداد {$group}.{$key}.",
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AdminPendingAction::STATUS_CONFIRMED,
            'executed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AdminPendingAction::STATUS_REJECTED,
        ]);
    }
}
