<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\GatedByAiSettings;
use App\Models\Page;
use App\Settings\AiSettings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Lists the published pages that have gone longest without an update — the
 * freshness companion to search_content: the assistant (and MCP clients) can
 * flag guide content that may be outdated. Reads pages.updated_at directly
 * (not the corpus), so it works even for pages not yet ingested. Gated on
 * the `search` feature toggle like the other content tools. Read-only.
 */
class ListStalePagesTool implements Tool
{
    use GatedByAiSettings;

    private const DEFAULT_MONTHS_THRESHOLD = 12;

    private const DEFAULT_LIMIT = 20;

    private const MAX_LIMIT = 50;

    public function __construct(private readonly AiSettings $settings) {}

    public function description(): Stringable|string
    {
        return 'List the published pages of the UQU College of Computing student guide that have not been updated for a long time '
            .'(عرض صفحات دليل طلاب كلية الحاسبات التي لم تُحدَّث منذ فترة طويلة — الأقدم تحديثاً أولاً). '
            .'Returns pages whose last update is at least months_threshold months old, oldest first, '
            .'each with its slug, title, last-updated date and days since the update. Read-only.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($this->aiToolsAreDisabled() || ! $this->settings->isFeatureEnabled('search')) {
            return 'البحث الذكي غير متاح حالياً. Smart search is currently disabled.';
        }

        $months = max(1, $request->integer('months_threshold', self::DEFAULT_MONTHS_THRESHOLD));
        $limit = min(self::MAX_LIMIT, max(1, $request->integer('limit', self::DEFAULT_LIMIT)));

        $pages = Page::query()
            ->visible()
            ->where('updated_at', '<=', now()->subMonths($months))
            ->orderBy('updated_at')
            ->limit($limit)
            ->get(['id', 'slug', 'title', 'updated_at']);

        if ($pages->isEmpty()) {
            return "لا توجد صفحات منشورة مضى على آخر تحديثها {$months} شهراً أو أكثر. No published pages are older than the threshold.";
        }

        $lines = $pages->map(function (Page $page, int $index): string {
            return sprintf(
                '%d. %s (slug: %s) — آخر تحديث: %s (منذ %d يوماً)',
                $index + 1,
                $page->title,
                $page->slug,
                $page->updated_at->toDateString(),
                (int) $page->updated_at->diffInDays(now()),
            );
        });

        return "الصفحات المنشورة الأقدم تحديثاً (لم تُحدَّث منذ {$months} شهراً أو أكثر):\n\n".$lines->implode("\n");
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'months_threshold' => $schema->integer()
                ->description('Only include pages whose last update is at least this many months old. Defaults to 12.'),
            'limit' => $schema->integer()
                ->description('Maximum number of pages to return, between 1 and 50. Defaults to 20.'),
        ];
    }
}
