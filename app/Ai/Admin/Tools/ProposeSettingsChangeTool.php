<?php

namespace App\Ai\Admin\Tools;

use App\Ai\Admin\SettingsRegistry;
use App\Ai\Admin\Tools\Concerns\GatedByAdminAssistant;
use App\Models\Ai\AdminPendingAction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Phase one of the admin assistant's write path for settings: validates the
 * group/key/value against the settings classes (by reflection) and persists
 * a PENDING action — it NEVER saves a setting itself. The change only happens
 * when the admin presses تأكيد, via {@see \App\Ai\Admin\ProposalExecutor}.
 */
class ProposeSettingsChangeTool implements Tool
{
    use GatedByAdminAssistant;

    public function __construct(private readonly SettingsRegistry $registry) {}

    public function description(): Stringable|string
    {
        return 'Propose changing ONE site setting to a new value (اقتراح تغيير قيمة إعداد واحد من إعدادات الموقع). '
            .'The change is NOT applied: it is saved as a pending proposal the admin must confirm in the UI. '
            .'Use get_settings first to see the available groups, keys, types, and current values. '
            .'Pass the value as a string: "true"/"false" for booleans, digits for numbers, JSON for arrays. '
            .'Returns the proposal id and its human summary.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($this->adminAssistantIsDisabled()) {
            return $this->adminAssistantDisabledReply();
        }

        $group = trim($request->string('group')->toString());
        $key = trim($request->string('key')->toString());
        $raw = $request->string('value')->toString();

        $keys = $this->registry->keysFor($group);

        if ($keys === []) {
            return 'تعذر إنشاء الاقتراح: مجموعة الإعدادات "'.$group.'" غير معروفة. المجموعات المتاحة: '
                .implode('، ', array_keys(SettingsRegistry::groups())).'.';
        }

        if (! array_key_exists($key, $keys)) {
            return 'تعذر إنشاء الاقتراح: لا يوجد إعداد باسم "'.$key.'" في المجموعة "'.$group.'". '
                .'استخدم أداة get_settings لمعرفة المفاتيح المتاحة.';
        }

        $casted = $this->registry->castValue($group, $key, $raw);

        if ($casted === null) {
            return 'تعذر إنشاء الاقتراح: القيمة "'.$raw.'" لا تطابق نوع الإعداد ('.$keys[$key].').';
        }

        $oldValue = $this->registry->currentValue($group, $key);
        $isSecret = $this->registry->isSecretKey($key);

        $payload = [
            'group' => $group,
            'key' => $key,
            'value' => $isSecret ? $this->registry->mask($casted['value']) : $casted['value'],
            'raw_value' => $raw,
            'old_value' => $isSecret ? $this->registry->mask($oldValue) : $oldValue,
        ];

        $summary = sprintf(
            'تغيير الإعداد %s.%s من %s إلى %s.',
            $group,
            $key,
            json_encode($payload['old_value'], JSON_UNESCAPED_UNICODE),
            json_encode($payload['value'], JSON_UNESCAPED_UNICODE),
        );

        $proposal = AdminPendingAction::query()->create([
            'type' => AdminPendingAction::TYPE_SETTINGS_CHANGE,
            'payload' => $payload,
            'summary' => $summary,
            'status' => AdminPendingAction::STATUS_PENDING,
            'proposed_by' => (int) Auth::id(),
        ]);

        return "تم إنشاء اقتراح بانتظار تأكيد المشرف — لم يُنفَّذ بعد.\n"
            ."الملخص: {$summary}\n"
            ."---\nproposal_id: {$proposal->id}";
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'group' => $schema->string()
                ->description('The settings group name, e.g. "ai" or "telegram" (from get_settings).')
                ->enum(array_keys(SettingsRegistry::groups()))
                ->required(),
            'key' => $schema->string()
                ->description('The setting key inside the group, exactly as returned by get_settings.')
                ->required(),
            'value' => $schema->string()
                ->description('The new value as a string: "true"/"false" for booleans, digits for numbers, plain text for strings, JSON for arrays.')
                ->required(),
        ];
    }
}
