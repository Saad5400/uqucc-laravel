<?php

namespace App\Ai\Admin\Actions\Settings;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\SettingsRegistry;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Dump every operator-editable settings group (values live, secrets masked to
 * their last 4 characters) so the model can ground a settings change in the
 * current state. Read-only.
 */
class GetSettingsAction extends AdminAction
{
    public function __construct(private readonly SettingsRegistry $registry) {}

    public function name(): string
    {
        return 'get_settings';
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function category(): string
    {
        return 'settings';
    }

    public function description(): string
    {
        return 'Read the current values of every site settings group (AI settings, Telegram settings) with each key\'s type '
            .'(قراءة القيم الحالية لجميع إعدادات الموقع مع نوع كل مفتاح). '
            .'Secret values are masked. Use the group and key names exactly as returned when changing a setting. Read-only.';
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        $sections = [];

        foreach (array_keys(SettingsRegistry::groups()) as $group) {
            $types = $this->registry->keysFor($group);
            $values = $this->registry->currentValues($group);
            $lines = ["## group: {$group}"];

            foreach ($values as $key => $value) {
                $lines[] = sprintf(
                    '- %s (%s) = %s',
                    $key,
                    $types[$key] ?? 'string',
                    is_string($value) && $this->registry->isSecretKey($key)
                        ? $value
                        : json_encode($value, JSON_UNESCAPED_UNICODE),
                );
            }

            $sections[] = implode("\n", $lines);
        }

        return ActionResult::text("إعدادات الموقع الحالية:\n\n".implode("\n\n", $sections));
    }
}
