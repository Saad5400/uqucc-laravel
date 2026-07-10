<?php

namespace App\Ai\Tools;

use App\Ai\Corpus\PageContentExtractor;
use App\Ai\Tools\Concerns\GatedByAiSettings;
use App\Models\Page;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Fetches one visible CMS page as markdown by its slug — the full-page
 * companion to search_content's snippets. Hidden pages are never exposed.
 * Read-only.
 */
class GetPageTool implements Tool
{
    use GatedByAiSettings;

    public function __construct(private readonly PageContentExtractor $extractor) {}

    public function description(): Stringable|string
    {
        return 'Fetch the full content of one page of the UQU College of Computing student guide as markdown, by its slug '
            .'(جلب محتوى صفحة كاملة من دليل طلاب كلية الحاسبات بصيغة ماركداون). '
            .'Use the slug returned by search_content, e.g. "/adwat/hasbh-almadl". Only publicly visible pages are available. Read-only.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($this->aiToolsAreDisabled()) {
            return $this->aiDisabledReply();
        }

        $slug = trim($request->string('slug')->toString());

        if ($slug === '') {
            return 'يرجى تحديد معرف الصفحة. Provide the page slug.';
        }

        $normalizedSlug = '/'.trim($slug, '/');

        $page = Page::query()
            ->visible()
            ->where('slug', $normalizedSlug)
            ->first();

        if ($page === null) {
            return "لم يتم العثور على صفحة بالمعرف \"{$normalizedSlug}\". No visible page matches this slug — use search_content to find the right one.";
        }

        // The date goes ABOVE the slug marker: CitationExtractor relies on
        // the "slug:" line staying the last line of a successful reply.
        $updated = $page->updated_at !== null
            ? 'آخر تحديث: '.$page->updated_at->toDateString()."\n"
            : '';

        return $this->extractor->extract($page)
            ."\n\n---\n{$updated}slug: {$page->slug}";
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'slug' => $schema->string()
                ->description('The page slug, with or without the leading slash (e.g. "/adwat/almkafa").')
                ->required(),
        ];
    }
}
