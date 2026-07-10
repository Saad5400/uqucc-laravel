<?php

namespace App\Http\Requests\Manage;

use App\Models\Page;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePageRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * Pages are editable by every panel user (parity with the original
     * admin panel, where page CRUD was gated on panel access only).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Every rule is `sometimes` so the workspace can send partial payloads
     * (inline title save, per-tab explicit saves) against one endpoint.
     *
     * @return array<string, array<int, \Illuminate\Validation\Rules\Exists|\Illuminate\Validation\Rules\Unique|Closure|string>>
     */
    public function rules(): array
    {
        /** @var Page $page */
        $page = $this->route('page');

        $arrayOrString = function (string $attribute, mixed $value, Closure $fail): void {
            if ($value !== null && ! is_array($value) && ! is_string($value)) {
                $fail('قيمة الحقل غير صالحة.');
            }
        };

        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => [
                'sometimes', 'required', 'string', 'max:255',
                'regex:/^\/[a-z0-9_\-\/]*$/',
                Rule::unique('pages', 'slug')->ignore($page->id),
            ],
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('pages', 'id')->whereNull('deleted_at')],
            'icon' => ['sometimes', 'nullable', 'string', 'max:255'],
            'hidden' => ['sometimes', 'boolean'],
            'hidden_from_bot' => ['sometimes', 'boolean'],
            'smart_search' => ['sometimes', 'boolean'],
            'requires_prefix' => ['sometimes', 'boolean'],
            'html_content' => ['sometimes', 'nullable', $arrayOrString],
            'quick_response_auto_extract_message' => ['sometimes', 'boolean'],
            'quick_response_auto_extract_buttons' => ['sometimes', 'boolean'],
            'quick_response_auto_extract_attachments' => ['sometimes', 'boolean'],
            'quick_response_send_link' => ['sometimes', 'boolean'],
            'quick_response_send_screenshot' => ['sometimes', 'boolean'],
            'quick_response_message' => ['sometimes', 'nullable', 'string'],
            'quick_response_buttons' => ['sometimes', 'nullable', 'array'],
            'quick_response_buttons.*' => ['array:text,url,size'],
            'quick_response_buttons.*.text' => ['required', 'string', 'max:50'],
            'quick_response_buttons.*.url' => ['required', 'string', 'url', 'max:2048'],
            'quick_response_buttons.*.size' => ['required', 'string', Rule::in(['full', 'half', 'third'])],
            'quick_response_attachments' => ['sometimes', 'nullable', 'array'],
            'quick_response_attachments.*' => ['string', 'max:2048'],
        ];
    }

    /**
     * Reject moving a page under itself or under one of its own descendants,
     * which would detach the whole subtree from the tree.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $parentId = $this->input('parent_id');

                if (! $this->has('parent_id') || $parentId === null) {
                    return;
                }

                /** @var Page $page */
                $page = $this->route('page');

                if ((int) $parentId === $page->id || in_array((int) $parentId, $this->descendantIds($page), true)) {
                    $validator->errors()->add('parent_id', 'لا يمكن نقل الصفحة تحت نفسها أو تحت إحدى صفحاتها الفرعية.');
                }
            },
        ];
    }

    /**
     * Ids of every descendant of the page, trashed ones included (a trashed
     * descendant can be restored later, so it must not become an ancestor).
     *
     * @return array<int, int>
     */
    protected function descendantIds(Page $page): array
    {
        $childrenByParent = Page::withTrashed()
            ->whereNotNull('parent_id')
            ->get(['id', 'parent_id'])
            ->groupBy('parent_id');

        $ids = [];
        $queue = [$page->id];

        while ($queue !== []) {
            $currentId = array_shift($queue);

            foreach ($childrenByParent->get($currentId, collect()) as $child) {
                $ids[] = $child->id;
                $queue[] = $child->id;
            }
        }

        return $ids;
    }

    /**
     * Get the custom validation error messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'حقل العنوان مطلوب.',
            'title.string' => 'يجب أن يكون العنوان نصاً.',
            'title.max' => 'يجب ألا يتجاوز العنوان ٢٥٥ حرفاً.',
            'slug.required' => 'حقل الرابط مطلوب.',
            'slug.string' => 'يجب أن يكون الرابط نصاً.',
            'slug.max' => 'يجب ألا يتجاوز الرابط ٢٥٥ حرفاً.',
            'slug.regex' => 'يجب أن يبدأ الرابط بشرطة مائلة (/) ويحتوي على أحرف لاتينية صغيرة وأرقام وشرطات فقط.',
            'slug.unique' => 'هذا الرابط مستخدم بالفعل في صفحة أخرى.',
            'parent_id.integer' => 'معرّف الصفحة الأب غير صالح.',
            'parent_id.exists' => 'الصفحة الأب المحددة غير موجودة.',
            'icon.string' => 'يجب أن يكون اسم الأيقونة نصاً.',
            'icon.max' => 'يجب ألا يتجاوز اسم الأيقونة ٢٥٥ حرفاً.',
            'hidden.boolean' => 'قيمة الإخفاء من الموقع غير صالحة.',
            'hidden_from_bot.boolean' => 'قيمة الإخفاء من البوت غير صالحة.',
            'smart_search.boolean' => 'قيمة البحث الذكي غير صالحة.',
            'requires_prefix.boolean' => 'قيمة خيار كلمة «دليل» غير صالحة.',
            'quick_response_auto_extract_message.boolean' => 'قيمة استخراج نص الرد غير صالحة.',
            'quick_response_auto_extract_buttons.boolean' => 'قيمة استخراج الأزرار غير صالحة.',
            'quick_response_auto_extract_attachments.boolean' => 'قيمة استخراج المرفقات غير صالحة.',
            'quick_response_send_link.boolean' => 'قيمة إرسال الرابط غير صالحة.',
            'quick_response_send_screenshot.boolean' => 'قيمة إرسال لقطة الشاشة غير صالحة.',
            'quick_response_message.string' => 'نص الرد غير صالح.',
            'quick_response_buttons.array' => 'قائمة الأزرار غير صالحة.',
            'quick_response_buttons.*.array' => 'بيانات الزر غير صالحة.',
            'quick_response_buttons.*.text.required' => 'عنوان الزر مطلوب.',
            'quick_response_buttons.*.text.string' => 'يجب أن يكون عنوان الزر نصاً.',
            'quick_response_buttons.*.text.max' => 'يجب ألا يتجاوز عنوان الزر ٥٠ حرفاً.',
            'quick_response_buttons.*.url.required' => 'رابط الزر مطلوب.',
            'quick_response_buttons.*.url.url' => 'يجب إدخال رابط زر صالح يبدأ بـ https:// أو http://.',
            'quick_response_buttons.*.url.max' => 'يجب ألا يتجاوز رابط الزر ٢٠٤٨ حرفاً.',
            'quick_response_buttons.*.size.required' => 'حجم الزر مطلوب.',
            'quick_response_buttons.*.size.in' => 'حجم الزر يجب أن يكون: عرض كامل أو نصف عرض أو ثلث عرض.',
            'quick_response_attachments.array' => 'قائمة المرفقات غير صالحة.',
            'quick_response_attachments.*.string' => 'مسار المرفق غير صالح.',
            'quick_response_attachments.*.max' => 'مسار المرفق طويل جداً.',
        ];
    }
}
