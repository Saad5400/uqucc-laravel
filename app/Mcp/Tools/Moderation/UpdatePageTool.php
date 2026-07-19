<?php

namespace App\Mcp\Tools\Moderation;

use App\Http\Requests\Manage\UpdatePageRequest;
use App\Models\Page;
use App\Models\PageChangeRequest;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;

/**
 * Update a guide page's settings. Mirrors the review-aware half of
 * {@see \App\Http\Controllers\Manage\PageController::update()}: for a
 * moderator whose edits are review-gated ({@see User::mustHaveChangesReviewed()})
 * the payload never touches the live page — it is captured (and merged into
 * their existing pending request for this page) for a reviewer to approve;
 * everyone else writes straight through Eloquent so the `Page::booted()` cache
 * flushes fire.
 *
 * Content (`html_content`, quick-response fields) and structural moves
 * (`parent_id`) stay in the `/manage` workspace; this tool covers the page's
 * title, slug, icon and visibility flags.
 */
#[Description('Update a guide page\'s title, slug, icon or visibility flags (تعديل إعدادات صفحة: العنوان، الرابط، الأيقونة، خيارات الإخفاء). Requires page_id (from list_managed_pages) and only the fields you want to change. If your account is in review mode, the edit is submitted to the review queue instead of published immediately. Page content editing stays in the admin panel.')]
class UpdatePageTool extends ModerationTool
{
    protected function requiredAbility(): string
    {
        return 'edit-content';
    }

    protected string $name = 'update_page';

    protected function perform(Request $request, User $user): Response
    {
        $page = Page::withTrashed()->find((int) $request->get('page_id'));

        if ($page === null) {
            return Response::error('لم يُعثر على الصفحة المطلوبة. No page found for that id.');
        }

        $data = $this->validateInput(
            $request,
            [
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
            ],
            (new UpdatePageRequest)->messages(),
        );

        if ($data instanceof Response) {
            return $data;
        }

        if ($data === []) {
            return Response::error('لم تُرسل أي حقول للتعديل. No editable fields were provided.');
        }

        if ($user->mustHaveChangesReviewed()) {
            $pending = $page->changeRequests()
                ->where('author_id', $user->getKey())
                ->where('status', PageChangeRequest::STATUS_PENDING)
                ->first();

            $page->changeRequests()->updateOrCreate(
                ['id' => $pending?->id],
                [
                    'author_id' => $user->getKey(),
                    'status' => PageChangeRequest::STATUS_PENDING,
                    'payload' => array_merge($pending?->payload ?? [], $data),
                ],
            );

            return Response::text('تم إرسال تعديلك للمراجعة. سيظهر على الموقع بعد اعتماده من مراجع. Your edit was submitted for review and will publish once approved.');
        }

        $page->update($data);

        return Response::text('تم تحديث الصفحة «'.$page->title.'». Page updated.');
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'page_id' => $schema->integer()
                ->description('The id of the page to update, from list_managed_pages.')
                ->required(),
            'title' => $schema->string()
                ->description('The page title.'),
            'slug' => $schema->string()
                ->description('The page slug, starting with "/" and using lowercase Latin letters, digits, hyphens and slashes only. Must be unique.'),
            'icon' => $schema->string()
                ->description('Optional icon name. Send empty to clear.'),
            'hidden' => $schema->boolean()
                ->description('Whether the page is hidden from the website.'),
            'hidden_from_bot' => $schema->boolean()
                ->description('Whether the page is hidden from the Telegram bot.'),
            'hidden_from_ai' => $schema->boolean()
                ->description('Whether the page is hidden from the AI assistant.'),
            'smart_search' => $schema->boolean()
                ->description('Whether the page is included in smart search.'),
            'requires_prefix' => $schema->boolean()
                ->description('Whether the bot requires the «دليل» keyword to surface this page.'),
        ];
    }
}
