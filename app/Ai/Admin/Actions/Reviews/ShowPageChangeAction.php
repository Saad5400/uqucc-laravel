<?php

namespace App\Ai\Admin\Actions\Reviews;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Ai\Copilot\TipTapContent;
use App\Models\Page;
use App\Models\PageChangeRequest;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * The review screen as text: a field-by-field diff of a change request's target
 * page — its current values against the proposed payload — so a moderator can
 * actually SEE what they are approving before deciding. `html_content` resolves
 * to markdown, booleans to true/false, `parent_id` to the parent page title.
 * Read-only. Mirrors {@see \App\Http\Controllers\Manage\PageChangeRequestController::show()}.
 */
class ShowPageChangeAction extends AdminAction
{
    /**
     * Human-readable Arabic labels for each editable page field, keyed by the
     * payload key {@see \App\Http\Requests\Manage\UpdatePageRequest} produces.
     *
     * @var array<string, string>
     */
    private const FIELD_LABELS = [
        'title' => 'العنوان',
        'slug' => 'الرابط',
        'parent_id' => 'الصفحة الأب',
        'icon' => 'الأيقونة',
        'hidden' => 'مخفية من الموقع',
        'hidden_from_bot' => 'مخفية من البوت',
        'smart_search' => 'البحث الذكي',
        'requires_prefix' => 'يتطلب كلمة «دليل»',
        'html_content' => 'المحتوى',
        'quick_response_message' => 'نص الرد السريع',
        'quick_response_buttons' => 'أزرار الرد السريع',
        'quick_response_attachments' => 'مرفقات الرد السريع',
        'quick_response_auto_extract_message' => 'استخراج نص الرد تلقائياً',
        'quick_response_auto_extract_buttons' => 'استخراج الأزرار تلقائياً',
        'quick_response_auto_extract_attachments' => 'استخراج المرفقات تلقائياً',
        'quick_response_send_link' => 'إرسال الرابط',
        'quick_response_send_screenshot' => 'إرسال لقطة الشاشة',
    ];

    /** @var array<string, string> */
    private const STATUS_LABELS = [
        PageChangeRequest::STATUS_PENDING => 'بانتظار المراجعة',
        PageChangeRequest::STATUS_APPROVED => 'معتمد',
        PageChangeRequest::STATUS_REJECTED => 'مرفوض',
    ];

    public function name(): string
    {
        return 'show_page_change';
    }

    public function requiredAbility(): ?string
    {
        return 'review-changes';
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function category(): string
    {
        return 'reviews';
    }

    public function description(): string
    {
        return 'Show a full field-by-field diff of a pending or decided page edit — the page\'s current '
            .'values against the proposed ones (عرض تفاصيل تعديل صفحة: مقارنة القيم الحالية بالمقترحة حقلاً حقلاً). '
            .'Provide change_request_id from list_pending_changes. Read-only — call this to see exactly what a '
            .'change proposes before approve_page_change or reject_page_change.';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input, User $user): array
    {
        $changeRequest = PageChangeRequest::query()->find((int) ($input['change_request_id'] ?? 0));

        if ($changeRequest === null) {
            throw new AdminActionException('لم يُعثر على التعديل المطلوب. استخدم list_pending_changes للتأكد من المعرّف.');
        }

        return ['change_request_id' => $changeRequest->id];
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'change_request_id' => $schema->integer()
                ->description('The id of the change request to inspect, from list_pending_changes.')
                ->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        $changeRequest = PageChangeRequest::query()
            ->with(['page', 'author', 'reviewer'])
            ->find((int) $normalized['change_request_id']);

        if ($changeRequest === null) {
            throw new AdminActionException('التعديل المطلوب لم يعد موجوداً.');
        }

        $page = $changeRequest->page;

        $header = [
            'تفاصيل التعديل رقم '.$changeRequest->id
                .($page === null ? ' (الصفحة المستهدفة محذوفة نهائياً)' : ' على صفحة «'.$page->title.'»')
                .' — الحالة: '.(self::STATUS_LABELS[$changeRequest->status] ?? $changeRequest->status),
            'الكاتب: '.($changeRequest->author?->name ?? '—'),
        ];

        if ($changeRequest->reviewer !== null) {
            $header[] = 'المراجع: '.$changeRequest->reviewer->name;
        }

        if ($changeRequest->review_note !== null && $changeRequest->review_note !== '') {
            $header[] = 'ملاحظة المراجعة: '.$changeRequest->review_note;
        }

        return ActionResult::text(
            implode("\n", $header)
            ."\n\nالتغييرات المقترحة (الحقل — الحالية ← المقترحة):\n"
            .implode("\n", $this->renderChanges($changeRequest, $page)),
        );
    }

    /**
     * A field-by-field diff of the target page's current values against the
     * proposed payload, rendered as text.
     *
     * @return list<string>
     */
    private function renderChanges(PageChangeRequest $changeRequest, ?Page $page): array
    {
        $lines = [];

        foreach ($changeRequest->payload as $key => $new) {
            $label = self::FIELD_LABELS[$key] ?? $key;
            $current = $page?->getAttribute($key);

            if ($key === 'html_content') {
                $old = TipTapContent::toMarkdown($page?->html_content);
                $newValue = TipTapContent::toMarkdown($new);

                $lines[] = '• '.$label.':';
                $lines[] = '  الحالية:'."\n".$this->indentBlock($old);
                $lines[] = '  المقترحة:'."\n".$this->indentBlock($newValue);

                continue;
            }

            if (is_bool($new)) {
                $lines[] = '• '.$label.': '.$this->boolLabel((bool) $current).' ← '.$this->boolLabel($new);

                continue;
            }

            $lines[] = '• '.$label.': '.$this->scalarize($key, $current).' ← '.$this->scalarize($key, $new);
        }

        return $lines;
    }

    private function boolLabel(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    private function indentBlock(string $value): string
    {
        if ($value === '') {
            return '    —';
        }

        return implode("\n", array_map(
            fn (string $line): string => '    '.$line,
            explode("\n", $value),
        ));
    }

    /**
     * Render a non-content field value as a display string.
     */
    private function scalarize(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if ($key === 'parent_id') {
            return Page::withTrashed()->find($value)?->title ?? '—';
        }

        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        return (string) $value;
    }
}
