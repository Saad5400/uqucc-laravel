<?php

namespace App\Ai\Admin\Tools;

use App\Ai\Admin\SettingsRegistry;
use App\Ai\Admin\Tools\Concerns\GatedByAdminAssistant;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Dumps every operator-editable settings group (values live, secrets masked
 * to their last 4 characters) so the model can ground a settings proposal in
 * the current state. Admin-only: NEVER registered in the public Toolbox.
 */
class GetSettingsTool implements Tool
{
    use GatedByAdminAssistant;

    public function __construct(private readonly SettingsRegistry $registry) {}

    public function description(): Stringable|string
    {
        return 'Read the current values of every site settings group (AI settings, Telegram settings) with each key\'s type '
            .'(قراءة القيم الحالية لجميع إعدادات الموقع مع نوع كل مفتاح). '
            .'Secret values are masked. Use the group and key names exactly as returned when proposing a settings change. Read-only.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($this->adminAssistantIsDisabled()) {
            return $this->adminAssistantDisabledReply();
        }

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

        return "إعدادات الموقع الحالية:\n\n".implode("\n\n", $sections);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
