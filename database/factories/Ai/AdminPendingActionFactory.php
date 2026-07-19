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
            'type' => 'manage_page_structure',
            'payload' => [
                'action' => 'manage_page_structure',
                'category' => 'pages',
                'input' => ['action' => 'rename', 'page_id' => 1, 'title' => fake()->sentence(3)],
                'preview' => ['action' => 'rename', 'page_id' => 1, 'title' => fake()->sentence(3)],
            ],
            'summary' => 'إعادة تسمية صفحة.',
            'status' => AdminPendingAction::STATUS_PENDING,
            'proposed_by' => User::factory(),
            'executed_at' => null,
            'error' => null,
        ];
    }

    /**
     * A proposal for any unified action, carrying the raw tool input the
     * executor re-validates and runs at confirm time.
     *
     * @param  array<string, mixed>  $input
     */
    public function forAction(string $type, array $input, string $category = 'pages', string $summary = 'اقتراح إداري.'): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => $type,
            'payload' => [
                'action' => $type,
                'category' => $category,
                'input' => $input,
                'preview' => $input,
            ],
            'summary' => $summary,
        ]);
    }

    public function settingsChange(string $group = 'ai', string $key = 'search_enabled', string $rawValue = 'true'): static
    {
        return $this->forAction(
            'update_setting',
            ['group' => $group, 'key' => $key, 'value' => $rawValue],
            'settings',
            "تغيير الإعداد {$group}.{$key}.",
        );
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
