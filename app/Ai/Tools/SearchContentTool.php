<?php

namespace App\Ai\Tools;

use App\Ai\Corpus\CorpusResultFormatter;
use App\Ai\Corpus\CorpusRetriever;
use App\Ai\Tools\Concerns\GatedByAiSettings;
use App\Settings\AiSettings;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Hybrid (keyword + vector) search over the site's content corpus — the same
 * retrieval the public search endpoint uses. Read-only. Gated on the
 * `search` feature toggle (which itself honors the master AI kill switch).
 */
class SearchContentTool implements Tool
{
    use GatedByAiSettings;

    private const MAX_LIMIT = 20;

    public function __construct(
        private readonly CorpusRetriever $retriever,
        private readonly CorpusResultFormatter $formatter,
        private readonly AiSettings $settings,
    ) {}

    public function description(): Stringable|string
    {
        return 'Search the UQU College of Computing student guide (uqucc) content. '
            .'The content is written in Arabic — prefer Arabic queries (البحث في محتوى دليل طلاب كلية الحاسبات بجامعة أم القرى). '
            .'Returns the most relevant content snippets with their page title, slug and section heading, best match first. '
            .'Use get_page with a returned slug to read a full page; results marked "(document: {id})" come from an uploaded '
            .'document (regulations/rules PDF) — read those with get_document instead (long documents return a table of '
            .'contents first; fetch the relevant sections by number). '
            .'The full public URL of a page is '.rtrim((string) config('app.url'), '/').'{slug}. Read-only.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($this->aiToolsAreDisabled() || ! $this->settings->isFeatureEnabled('search')) {
            return 'البحث الذكي غير متاح حالياً. Smart search is currently disabled.';
        }

        $query = trim($request->string('query')->toString());

        if (mb_strlen($query) < 2) {
            return 'يرجى إدخال كلمات بحث من حرفين على الأقل. Provide a search query of at least 2 characters.';
        }

        $limit = min(self::MAX_LIMIT, max(1, $request->integer('limit', 8)));

        $results = $this->retriever->search($query, $limit, includeHidden: true);

        if ($results->isEmpty()) {
            return "لا توجد نتائج مطابقة لـ \"{$query}\". No results matched.";
        }

        return "نتائج البحث عن \"{$query}\":\n\n".$this->formatter->render($results);
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->description('Search terms (2-100 characters). The site content is Arabic, so Arabic terms match best.')
                ->required(),
            'limit' => $schema->integer()
                ->description('Maximum number of results to return, between 1 and 20. Defaults to 8.'),
        ];
    }
}
