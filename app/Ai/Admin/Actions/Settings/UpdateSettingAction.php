<?php

namespace App\Ai\Admin\Actions\Settings;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Ai\Admin\SettingsRegistry;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Change ONE settings value (e.g. a feature toggle or model id), validated and
 * cast against the key's declared type through {@see SettingsRegistry} — the
 * same registry the panel's settings editors and the read action use. Secret
 * values are never echoed back in the summary.
 */
class UpdateSettingAction extends AdminAction
{
    public function __construct(private readonly SettingsRegistry $registry) {}

    public function name(): string
    {
        return 'update_setting';
    }

    public function category(): string
    {
        return 'settings';
    }

    public function description(): string
    {
        return 'Change one site setting value (تغيير قيمة إعداد واحد في الموقع). '
            .'Provide the group and key exactly as returned by get_settings, and the new value as a string '
            .'(booleans as "true"/"false", numbers as digits, arrays as JSON). Read get_settings first.';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input, User $user): array
    {
        $group = trim((string) ($input['group'] ?? ''));
        $key = trim((string) ($input['key'] ?? ''));
        $raw = (string) ($input['value'] ?? '');

        if ($group === '' || ! array_key_exists($group, SettingsRegistry::groups())) {
            throw new AdminActionException('مجموعة الإعدادات غير معروفة. استخدم get_settings لمعرفة المجموعات المتاحة.');
        }

        if ($key === '' || ! array_key_exists($key, $this->registry->keysFor($group))) {
            throw new AdminActionException('الإعداد '.$group.'.'.$key.' غير موجود. استخدم get_settings للتأكد من الأسماء.');
        }

        $casted = $this->registry->castValue($group, $key, $raw);

        if ($casted === null) {
            throw new AdminActionException('القيمة المقترحة لا تطابق نوع الإعداد.');
        }

        $secret = $this->registry->isSecretKey($key);
        $currentValue = $this->registry->currentValue($group, $key);

        return [
            'group' => $group,
            'key' => $key,
            'raw_value' => $raw,
            'value' => $secret ? $this->registry->mask($casted['value']) : $casted['value'],
            'old_value' => $secret ? $this->registry->mask($currentValue) : $currentValue,
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function summarize(array $normalized, User $user): string
    {
        $new = is_string($normalized['value']) ? $normalized['value'] : json_encode($normalized['value'], JSON_UNESCAPED_UNICODE);

        return 'تغيير الإعداد '.$normalized['group'].'.'.$normalized['key'].' إلى: '.$new;
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        $group = (string) $normalized['group'];
        $key = (string) $normalized['key'];

        if (! array_key_exists($key, $this->registry->keysFor($group))) {
            throw new AdminActionException('الإعداد '.$group.'.'.$key.' لم يعد موجوداً.');
        }

        $casted = $this->registry->castValue($group, $key, (string) $normalized['raw_value']);

        if ($casted === null) {
            throw new AdminActionException('القيمة المقترحة لا تطابق نوع الإعداد.');
        }

        $this->registry->apply($group, $key, $casted['value']);

        return ActionResult::text('تم تحديث الإعداد '.$group.'.'.$key.'.');
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'group' => $schema->string()
                ->description('The settings group name, exactly as returned by get_settings (e.g. "ai" or "telegram").')
                ->required(),
            'key' => $schema->string()
                ->description('The setting key within the group, exactly as returned by get_settings.')
                ->required(),
            'value' => $schema->string()
                ->description('The new value as a string: booleans as "true"/"false", numbers as digits, arrays as JSON.')
                ->required(),
        ];
    }
}
