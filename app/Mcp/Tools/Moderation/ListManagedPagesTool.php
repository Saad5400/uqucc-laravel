<?php

namespace App\Mcp\Tools\Moderation;

use App\Models\Page;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Every guide page with its id and management state — hidden and trashed
 * pages included, unlike the public content tools. The lookup a client needs
 * before update_page / trash_page.
 */
#[IsReadOnly]
#[Description('List all guide pages with their ids and management state, including hidden and trashed pages (قائمة كل صفحات الدليل مع المعرّفات وحالتها، شاملة المخفية والمحذوفة). Use the ids with update_page / trash_page.')]
class ListManagedPagesTool extends ModerationTool
{
    protected string $name = 'list_managed_pages';

    protected function requiredAbility(): string
    {
        return 'edit-content';
    }

    protected function perform(Request $request, User $user): Response
    {
        $pages = Page::withTrashed()
            ->orderBy('order')
            ->orderBy('id')
            ->get()
            ->map(fn (Page $page): array => [
                'id' => $page->id,
                'title' => $page->title,
                'slug' => $page->slug,
                'parent_id' => $page->parent_id,
                'hidden' => (bool) $page->hidden,
                'hidden_from_bot' => (bool) $page->hidden_from_bot,
                'smart_search' => (bool) $page->smart_search,
                'trashed' => $page->trashed(),
            ]);

        return Response::text((string) json_encode($pages, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
