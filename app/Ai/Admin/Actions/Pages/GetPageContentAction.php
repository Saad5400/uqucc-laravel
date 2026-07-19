<?php

namespace App\Ai\Admin\Actions\Pages;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Ai\Copilot\TipTapContent;
use App\Models\Page;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Read one page's CURRENT body as markdown by id — including hidden pages —
 * so the assistant can inspect the real content before proposing an edit with
 * update_page_content. Read-only. Unlike the public get_page (visible pages,
 * by slug) this is the operator-side raw view for editing.
 */
class GetPageContentAction extends AdminAction
{
    public function name(): string
    {
        return 'get_page_content';
    }

    public function requiredAbility(): ?string
    {
        return 'edit-content';
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function category(): string
    {
        return 'pages';
    }

    public function description(): string
    {
        return 'Read the current text content of one page (by id, including hidden pages) as markdown '
            .'(قراءة المحتوى الحالي لصفحة بصيغة ماركداون عبر معرفها). '
            .'Use it before proposing an edit with update_page_content. Read-only.';
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'page_id' => $schema->integer()
                ->description('The id of the page to read, from list_pages.')
                ->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        $page = Page::withTrashed()->find((int) ($normalized['page_id'] ?? 0));

        if ($page === null) {
            throw new AdminActionException('لا توجد صفحة بهذا المعرّف. استخدم list_pages للتأكد.');
        }

        $markdown = trim(TipTapContent::toMarkdown($page->html_content));

        $header = 'صفحة «'.$page->title.'» (id: '.$page->id.' | slug: '.$page->slug.")\n\n";

        return ActionResult::text($header.($markdown === '' ? '«الصفحة فارغة حالياً.»' : $markdown));
    }
}
