<?php

namespace App\Ai\Admin\Actions\Pages;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Ai\Admin\Actions\Concerns\InteractsWithPages;
use App\Http\Requests\Manage\UpdatePageRequest;
use App\Models\Page;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Saad\AiKit\Approvals\Classified\Field;
use Saad\AiKit\Approvals\Classified\FieldWidget;

/**
 * Update a page's settings — title, slug, icon and visibility flags — mirroring
 * the review-aware half of {@see \App\Http\Controllers\Manage\PageController::update()}.
 * A review-mode author's edit is captured into the pending change-request queue
 * instead of published. Body content and structure live in update_page_content
 * and manage_page_structure. Reuses {@see UpdatePageRequest} messages.
 */
class UpdatePageAction extends AdminAction
{
    use InteractsWithPages;

    /** The page-setting fields this action may change, with Arabic labels. */
    private const FIELD_LABELS = [
        'title' => 'العنوان',
        'slug' => 'الرابط',
        'icon' => 'الأيقونة',
        'hidden' => 'الإخفاء من الموقع',
        'hidden_from_bot' => 'الإخفاء من البوت',
        'hidden_from_ai' => 'الإخفاء من الذكاء الاصطناعي',
        'smart_search' => 'الرد على أي رسالة تتضمن العنوان',
        'requires_prefix' => 'اشتراط كلمة «دليل»',
    ];

    public function name(): string
    {
        return 'update_page';
    }

    public function requiredAbility(): ?string
    {
        return 'edit-content';
    }

    public function category(): string
    {
        return 'pages';
    }

    public function description(): string
    {
        return 'Update a page\'s title, slug, icon or visibility flags. '
            .'Provide page_id (from list_pages) and only the fields to change. '
            .'For the page\'s text content use update_page_content instead.';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input, User $user): array
    {
        $page = Page::withTrashed()->find((int) ($input['page_id'] ?? 0));

        if ($page === null) {
            throw new AdminActionException('لا توجد صفحة بهذا المعرّف. استخدم list_pages للتأكد.');
        }

        $validator = Validator::make($input, [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => [
                'sometimes', 'required', 'string', 'max:255',
                'regex:/^\/[a-z0-9_\-\/]*$/',
                Rule::unique('pages', 'slug')->ignore($page->id),
            ],
            'icon' => ['sometimes', 'nullable', 'string', 'max:255'],
            'hidden' => ['sometimes', 'boolean'],
            'hidden_from_bot' => ['sometimes', 'boolean'],
            'hidden_from_ai' => ['sometimes', 'boolean'],
            'smart_search' => ['sometimes', 'boolean'],
            'requires_prefix' => ['sometimes', 'boolean'],
        ], (new UpdatePageRequest)->messages());

        if ($validator->fails()) {
            throw new AdminActionException($validator->errors()->first());
        }

        $fields = $validator->validated();

        if ($fields === []) {
            throw new AdminActionException('لم تُرسل أي حقول للتعديل.');
        }

        return [
            'page_id' => $page->id,
            'page_title' => $page->title,
            'fields' => $fields,
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function summarize(array $normalized, User $user): string
    {
        $labels = array_map(
            fn (string $key): string => self::FIELD_LABELS[$key] ?? $key,
            array_keys($normalized['fields']),
        );

        return 'تعديل إعدادات صفحة «'.$normalized['page_title'].'» ('.implode('، ', $labels).').';
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        $page = Page::withTrashed()->find((int) $normalized['page_id']);

        if ($page === null) {
            throw new AdminActionException('الصفحة المستهدفة لم تعد موجودة.');
        }

        return $this->writeOrQueue(
            $page,
            $normalized['fields'],
            $user,
            'تم تحديث إعدادات الصفحة «'.$page->title.'».',
        );
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'page_id' => $schema->integer()
                ->description('The id of the page to update, from list_pages.')
                ->required(),
            'title' => $schema->string()->description('The page title.'),
            'slug' => $schema->string()
                ->description('The page slug: starts with "/", lowercase Latin letters, digits, hyphens and slashes only. Must be unique.'),
            'icon' => $schema->string()->description('Optional icon name. Send empty to clear.'),
            'hidden' => $schema->boolean()->description('Whether the page is hidden from the website.'),
            'hidden_from_bot' => $schema->boolean()->description('Whether the page is hidden from the Telegram bot.'),
            'hidden_from_ai' => $schema->boolean()->description('Whether the page is hidden from the AI assistant.'),
            'smart_search' => $schema->boolean()->description('Whether the Telegram bot replies with this page to ANY message that contains its title, not only to a lookup of it.'),
            'requires_prefix' => $schema->boolean()->description('Whether the Telegram bot only surfaces this page when the message carries the guide keyword prefix.'),
        ];
    }

    /**
     * Arabic labels for the approval card, drawn from the SAME
     * {@see FIELD_LABELS} vocabulary {@see summarize()} writes its sentence
     * from, so the card's labels and its summary cannot drift apart.
     *
     * Each field's widget is restated alongside its label because declaring
     * a spec REPLACES the kit's value-based inference for that argument: an
     * id declared without `Field::readonly` would come back as an editable
     * text box, and a boolean as a text input.
     *
     * @return array<string, mixed>
     */
    public function fieldWidgets(): array
    {
        $widgets = [
            'title' => FieldWidget::Text,
            'slug' => FieldWidget::Text,
            'icon' => FieldWidget::Text,
            'hidden' => FieldWidget::Boolean,
            'hidden_from_bot' => FieldWidget::Boolean,
            'hidden_from_ai' => FieldWidget::Boolean,
            'smart_search' => FieldWidget::Boolean,
            'requires_prefix' => FieldWidget::Boolean,
        ];

        $fields = ['page_id' => Field::readonly('page_id', label: 'الصفحة')];

        foreach ($widgets as $name => $widget) {
            $fields[$name] = Field::make($name, $widget, label: self::FIELD_LABELS[$name]);
        }

        return $fields;
    }
}
