<?php

namespace App\Ai\Admin\Actions\Pages;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Ai\Admin\Actions\Concerns\InteractsWithPages;
use App\Ai\Copilot\TipTapContent;
use App\Models\Page;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Replace a page's TEXT content from markdown — the capability that was
 * previously panel-only. The markdown is converted to the editor's TipTap
 * document (reusing {@see TipTapContent}, the same pipeline the authoring
 * copilot uses), and any customBlock/alert nodes on the current page are
 * preserved by appending them unchanged after the new content (the frozen
 * custom-block contract: the model never sees them, so it cannot recreate
 * them). Review-mode authors' edits go to the review queue.
 */
class UpdatePageContentAction extends AdminAction
{
    use InteractsWithPages;

    public function name(): string
    {
        return 'update_page_content';
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
        return 'Replace a page\'s text content, given as markdown '
            .'(تحديث نص محتوى صفحة، يُرسَل بصيغة ماركداون). '
            .'Provide page_id (from list_pages) and the full new content as markdown — it replaces the current body. '
            .'Read the current content first with get_page_content. Custom alert/block components on the page are kept automatically. '
            .'Do not include a top-level H1 title; use ## and below.';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input, User $user): array
    {
        $page = Page::query()->find((int) ($input['page_id'] ?? 0));

        if ($page === null) {
            throw new AdminActionException('لا توجد صفحة قابلة للتحرير بهذا المعرّف. استخدم list_pages للتأكد.');
        }

        $content = trim((string) ($input['content'] ?? ''));

        if ($content === '') {
            throw new AdminActionException('نص المحتوى الجديد مطلوب.');
        }

        return [
            'page_id' => $page->id,
            'page_title' => $page->title,
            'content' => $content,
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function summarize(array $normalized, User $user): string
    {
        return 'تحديث محتوى صفحة «'.$normalized['page_title'].'» ('.mb_strlen($normalized['content']).' حرفاً من المحتوى الجديد).';
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        $page = Page::query()->find((int) $normalized['page_id']);

        if ($page === null) {
            throw new AdminActionException('الصفحة المستهدفة لم تعد موجودة.');
        }

        $document = TipTapContent::toDocument((string) $normalized['content']);
        $preserved = $this->customNodesIn($page->html_content);

        if ($preserved !== []) {
            $document['content'] = array_merge(array_values($document['content'] ?? []), $preserved);
        }

        return $this->writeOrQueue(
            $page,
            ['html_content' => $document],
            $user,
            'تم تحديث محتوى الصفحة «'.$page->title.'».'
                .($preserved !== [] ? ' (أُبقيت المكوّنات المخصّصة كما هي بعد المحتوى الجديد.)' : ''),
        );
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'page_id' => $schema->integer()
                ->description('The id of the page whose content to replace, from list_pages.')
                ->required(),
            'content' => $schema->string()
                ->description('The full new page content as markdown. Replaces the current body. Use ## headings and below, no top-level H1.')
                ->required(),
        ];
    }

    /**
     * Top-level blocks of the current TipTap document that contain customBlock
     * or alert nodes — preserved byte-identical across a content rewrite.
     *
     * @param  array<string, mixed>|string|null  $content
     * @return list<array<string, mixed>>
     */
    private function customNodesIn(array|string|null $content): array
    {
        if (! is_array($content)) {
            return [];
        }

        return array_values(array_filter(
            array_values($content['content'] ?? []),
            fn (mixed $node): bool => is_array($node) && $this->containsCustomNode($node),
        ));
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function containsCustomNode(array $node): bool
    {
        if (in_array($node['type'] ?? null, ['customBlock', 'alert'], true)) {
            return true;
        }

        foreach ((array) ($node['content'] ?? []) as $child) {
            if (is_array($child) && $this->containsCustomNode($child)) {
                return true;
            }
        }

        return false;
    }
}
