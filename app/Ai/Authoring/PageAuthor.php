<?php

namespace App\Ai\Authoring;

use App\Ai\Copilot\CopilotDisabledException;
use App\Ai\Copilot\TipTapContent;
use App\Ai\Corpus\CorpusRetriever;
use App\Ai\Corpus\CorpusSearchResult;
use App\Ai\Corpus\CorpusSourceType;
use App\Ai\Spend\SpendLedger;
use App\Models\Ai\PageContentProposal;
use App\Models\Corpus\CorpusDocument;
use App\Models\Page;
use App\Settings\AiSettings;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Document → page authoring: turns one extracted corpus document into either
 * a NEW unpublished draft page or a review-gated UPDATE PROPOSAL for an
 * existing page — never a change to live content.
 *
 * The flow (run from {@see \App\Jobs\Ai\AuthorPageFromDocumentJob}):
 *
 *  1. Match: {@see CorpusRetriever} searches the corpus with the document's
 *     title + an excerpt; page-sourced hits become update candidates.
 *  2. Decide: with candidates present, ONE structured generation picks
 *     "update page N" or "new" (no candidates skips the call — it is new).
 *  3. NEW → draft markdown → TipTap JSON → an UNPUBLISHED page (hidden,
 *     parentless) linked back to the document.
 *  4. UPDATE → a revised full-page markdown stored as a pending
 *     {@see PageContentProposal}; applying later re-validates and writes
 *     through Eloquent so the Page::booted() cache flushes fire.
 *
 * customBlock contract: the model only ever sees the page's TEXT flattening,
 * so customBlock/alert nodes cannot survive a rewrite in place. When the
 * target page contains them, applying appends the original nodes UNCHANGED
 * after the revised content (original order preserved) and the proposal
 * summary tells the reviewer a manual re-position may be needed.
 *
 * Gated like every paid admin feature: admin_copilot flag (honours the master
 * kill switch), the OpenRouter key, and the daily spend budget; each model
 * call's exact cost is recorded under the `authoring` feature.
 */
class PageAuthor
{
    /** Spend-ledger feature key for authoring generations. */
    public const FEATURE = 'authoring';

    /** How many existing pages the decision step may choose between. */
    private const MAX_CANDIDATES = 5;

    /** Cap (characters) on the document markdown injected into a prompt. */
    private const MAX_DOCUMENT_CHARS = 50_000;

    /** Cap (characters) on the document excerpt shown to the decision step. */
    private const DECISION_EXCERPT_CHARS = 1_500;

    private const DECIDE_INSTRUCTIONS = <<<'PROMPT'
        أنت محرر محتوى لموقع «دليل طالب كلية الحاسبات» بجامعة أم القرى.
        ستُعطى مستنداً مرفوعاً (عنوانه ومقتطفاً من نصه) وقائمة صفحات موجودة في الموقع مرشحة للتحديث.
        مهمتك قرار واحد فقط: هل هذا المستند تحديثٌ لمحتوى إحدى الصفحات المرشحة، أم محتوى جديد لا تغطيه أي منها؟

        القواعد:
        - اختر «update» فقط إذا كانت الصفحة تتناول الموضوع نفسه الذي يتناوله المستند (نفس اللائحة أو الخدمة أو الدليل) — لا يكفي تشابه عام في المجال.
        - عند الشك اختر «new».
        - أعد الناتج بصيغة JSON فقط بأحد الشكلين بالضبط: {"decision": "new"} أو {"decision": "update", "page_id": 12}
        - يجب أن يكون page_id أحد معرفات الصفحات المرشحة المذكورة.
        - بدون أي نص آخر وبدون أسوار أكواد حول الناتج.
        PROMPT;

    private const DRAFT_INSTRUCTIONS = <<<'PROMPT'
        أنت كاتب محتوى عربي محترف لموقع «دليل طالب كلية الحاسبات» بجامعة أم القرى — دليل إرشادي موجز لطلاب الكلية.
        مهمتك تحويل مستند مرفوع (لائحة، دليل، إعلان) إلى صفحة جديدة في الدليل بصيغة ماركداون.

        القواعد:
        - اكتب بالعربية الفصحى المبسطة وبنبرة الدليل الإرشادية الموجهة للطالب، وأبقِ المصطلحات التقنية الإنجليزية كما تُكتب عادة.
        - ابدأ الناتج بسطر واحد هو عنوان الصفحة من المستوى الأول (#) — قصيراً وواضحاً — ثم اكتب المحتوى بعناوين من المستوى الثاني (##) فما دون.
        - نظّم المحتوى: فقرات قصيرة وقوائم نقطية أو مرقمة عند الحاجة. لا تستخدم الجداول إطلاقاً — حوّل أي جدول إلى قائمة.
        - انقل ما يهم الطالب من المستند بدقة (الشروط، الأرقام، المواد، الخطوات) ولا تختلق أي معلومة غير واردة فيه.
        - تجاهل ما لا يفيد الطالب: الديباجات الرسمية، التواقيع، الأختام، أرقام الصفحات، وأوصاف الصور بين معقوفين مثل [نص غير مقروء].
        - أعد الناتج بصيغة ماركداون فقط — بدون مقدمات أو تعليقات أو أسوار أكواد حول الناتج.
        PROMPT;

    private const REVISE_INSTRUCTIONS = <<<'PROMPT'
        أنت محرر محتوى عربي محترف لموقع «دليل طالب كلية الحاسبات» بجامعة أم القرى.
        ستُعطى المحتوى الحالي لصفحة من الموقع، ومستنداً مرفوعاً جديداً يتعلق بموضوعها.
        مهمتك إعادة كتابة نص الصفحة كاملاً بصيغة ماركداون بعد دمج مستجدات المستند فيه.

        القواعد:
        - حافظ على بنية الصفحة الحالية وعناوينها وأسلوبها قدر الإمكان؛ حدّث ما غيّره المستند، وأضف ما استجد، واحذف ما لم يعد صحيحاً.
        - استخدم عناوين من المستوى الثاني (##) فما دون — لا تضع عنواناً من المستوى الأول.
        - فقرات قصيرة وقوائم عند الحاجة، ولا تستخدم الجداول إطلاقاً — حوّل أي جدول إلى قائمة.
        - لا تختلق معلومات غير واردة في الصفحة أو المستند، وأبقِ الروابط الموجودة كما هي.
        - إذا وجدت في نص الصفحة سطوراً بين معقوفين مثل [صورة: ...] أو [محتوى صورة: ...] فهي أوصاف تقنية — تجاهلها ولا تُعِد كتابتها.
        - أعد نص الصفحة كاملاً بصيغة ماركداون فقط — بدون مقدمات أو تعليقات أو أسوار أكواد حول الناتج.
        PROMPT;

    public function __construct(
        private readonly AiSettings $settings,
        private readonly SpendLedger $ledger,
        private readonly CorpusRetriever $retriever,
    ) {}

    /**
     * Whether the authoring feature may run: same flag as the page copilot —
     * no separate toggle, both are "AI drafts content for admin review".
     */
    public function isEnabled(): bool
    {
        return $this->settings->isFeatureEnabled('admin_copilot');
    }

    /**
     * Why authoring is unavailable, for disabled-with-reason UX — null while
     * it is enabled.
     */
    public function disabledReason(): ?string
    {
        if (! $this->settings->ai_enabled) {
            return 'الذكاء الاصطناعي معطل بالكامل. فعّل «تفعيل الذكاء الاصطناعي» من صفحة الإعدادات أولاً.';
        }

        if (! $this->settings->admin_copilot_enabled) {
            return 'مساعد الكتابة الذكي معطل. فعّله من صفحة الإعدادات لتوليد الصفحات من المستندات.';
        }

        return null;
    }

    /**
     * Author from one extracted document: returns the created draft Page (new
     * content) or the pending PageContentProposal (update to an existing
     * page). Throws with an operator-facing Arabic message on any refusal.
     */
    public function author(CorpusDocument $document): Page|PageContentProposal
    {
        $this->ensureEnabled();

        if ($document->status !== CorpusDocument::STATUS_READY || blank($document->extracted_markdown)) {
            throw new RuntimeException('لا يمكن توليد صفحة قبل اكتمال استخراج نص المستند.');
        }

        if (! $this->ledger->hasBudgetRemaining()) {
            throw new RuntimeException($this->ledger->budgetExhaustedMessage());
        }

        $candidates = $this->candidatePages($document);
        $target = $this->decideTarget($document, $candidates);

        return $target === null
            ? $this->draftNewPage($document)
            : $this->proposeUpdate($document, $target);
    }

    /**
     * Apply a pending proposal to its page through Eloquent (model events —
     * cache flushes, corpus re-ingest — fire). customBlock/alert nodes of the
     * CURRENT page content are preserved by appending them unchanged after
     * the revised content. Failures land on the row as status `failed`.
     */
    public function applyProposal(PageContentProposal $proposal): PageContentProposal
    {
        if (! $proposal->isPending()) {
            return $proposal;
        }

        $page = $proposal->page;

        try {
            if ($page === null || $page->trashed()) {
                throw new RuntimeException('الصفحة المستهدفة لم تعد موجودة — لا يمكن تطبيق الاقتراح.');
            }

            $content = $proposal->proposed_html_content
                ?? TipTapContent::toDocument($proposal->proposed_markdown);

            $preserved = $this->customNodesIn($page->html_content);

            if ($preserved !== []) {
                $content['content'] = array_merge(array_values($content['content'] ?? []), $preserved);
            }

            $page->update(['html_content' => $content]);

            $proposal->update([
                'status' => PageContentProposal::STATUS_APPLIED,
                'applied_at' => now(),
                'error' => null,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            $proposal->update([
                'status' => PageContentProposal::STATUS_FAILED,
                'error' => Str::limit($exception->getMessage(), 1000),
            ]);
        }

        return $proposal->refresh();
    }

    /**
     * Decline a pending proposal; the page is untouched.
     */
    public function rejectProposal(PageContentProposal $proposal): PageContentProposal
    {
        if ($proposal->isPending()) {
            $proposal->update(['status' => PageContentProposal::STATUS_REJECTED]);
        }

        return $proposal->refresh();
    }

    /**
     * Existing pages the document might be updating: page-sourced corpus hits
     * for the document title + a content excerpt, deduplicated, resolved to
     * live pages.
     *
     * @return array<int, array{page: Page, snippet: string}> Keyed by page id.
     */
    private function candidatePages(CorpusDocument $document): array
    {
        $query = trim($document->title.' '.Str::limit((string) $document->extracted_markdown, 300, ''));

        $pageResults = $this->retriever->search($query, self::MAX_CANDIDATES * 3)
            ->filter(fn (CorpusSearchResult $result): bool => $result->sourceType === CorpusSourceType::Page)
            ->unique(fn (CorpusSearchResult $result): int => $result->sourceId)
            ->take(self::MAX_CANDIDATES);

        $pages = Page::query()
            ->findMany($pageResults->map(fn (CorpusSearchResult $result): int => $result->sourceId))
            ->keyBy('id');

        $candidates = [];

        foreach ($pageResults as $result) {
            $page = $pages->get($result->sourceId);

            if ($page !== null) {
                $candidates[$page->id] = [
                    'page' => $page,
                    'snippet' => Str::limit(trim($result->content), 200),
                ];
            }
        }

        return $candidates;
    }

    /**
     * The single structured decision: null for NEW content, the target Page
     * for an UPDATE. Skipped (→ new) when retrieval found no candidates.
     *
     * @param  array<int, array{page: Page, snippet: string}>  $candidates
     */
    private function decideTarget(CorpusDocument $document, array $candidates): ?Page
    {
        if ($candidates === []) {
            return null;
        }

        $lines = [];

        foreach ($candidates as $candidate) {
            $lines[] = sprintf(
                '- page_id: %d | الرابط: %s | العنوان: %s | مقتطف: %s',
                $candidate['page']->id,
                $candidate['page']->slug,
                $candidate['page']->title,
                $candidate['snippet'],
            );
        }

        $prompt = 'المستند المرفوع:'."\n"
            .'العنوان: '.trim($document->title)."\n"
            .'مقتطف من نصه:'."\n".Str::limit(trim((string) $document->extracted_markdown), self::DECISION_EXCERPT_CHARS)."\n\n"
            .'الصفحات المرشحة:'."\n".implode("\n", $lines);

        $decision = $this->decodeDecision($this->generate(self::DECIDE_INSTRUCTIONS, $prompt));

        if ($decision['decision'] === 'new') {
            return null;
        }

        $target = $candidates[$decision['page_id']] ?? null;

        if ($target === null) {
            throw new RuntimeException('أعاد النموذج معرّف صفحة من خارج الصفحات المرشحة — حاول مرة أخرى.');
        }

        return $target['page'];
    }

    /**
     * NEW content: draft the page markdown and create an UNPUBLISHED,
     * parentless page (the admin categorizes and publishes after review).
     */
    private function draftNewPage(CorpusDocument $document): Page
    {
        $prompt = 'حوّل المستند التالي إلى صفحة جديدة في الدليل.'."\n\n"
            .'عنوان المستند: '.trim($document->title)."\n\n"
            .'نص المستند:'."\n\n".$this->documentMarkdown($document);

        $markdown = $this->generate(self::DRAFT_INSTRUCTIONS, $prompt);

        if (trim($markdown) === '') {
            throw new RuntimeException('أعاد النموذج ناتجاً فارغاً — حاول مرة أخرى.');
        }

        [$title, $body] = $this->splitTitle($markdown, fallbackTitle: trim($document->title));

        return Page::create([
            'title' => $title,
            'slug' => $this->generateUniqueSlug($title),
            'parent_id' => null,
            'hidden' => true,
            'html_content' => TipTapContent::toDocument($body),
        ]);
    }

    /**
     * UPDATE: generate the revised full-page markdown and persist it as a
     * pending proposal — the live page stays untouched until a human applies.
     */
    private function proposeUpdate(CorpusDocument $document, Page $page): PageContentProposal
    {
        $currentMarkdown = TipTapContent::toMarkdown($page->html_content);

        $prompt = 'أعد كتابة الصفحة التالية بعد دمج مستجدات المستند فيها.'."\n\n"
            .'عنوان الصفحة: '.trim($page->title)."\n\n"
            .'المحتوى الحالي للصفحة:'."\n\n".$currentMarkdown."\n\n"
            .'عنوان المستند: '.trim($document->title)."\n\n"
            .'نص المستند:'."\n\n".$this->documentMarkdown($document);

        $revised = $this->generate(self::REVISE_INSTRUCTIONS, $prompt);

        if (trim($revised) === '') {
            throw new RuntimeException('أعاد النموذج ناتجاً فارغاً — حاول مرة أخرى.');
        }

        $summary = 'اقتراح تحديث صفحة «'.$page->title.'» من مستند «'.$document->title.'».';

        if ($this->customNodesIn($page->html_content) !== []) {
            $summary .= ' تنبيه: تحتوي الصفحة على مكوّنات مخصّصة (تنبيهات/بلوكات) لا يعدّلها الذكاء الاصطناعي — عند التطبيق تبقى كما هي وتُلحق بعد المحتوى المقترح، وقد تحتاج إعادة ترتيب يدوياً في المحرر.';
        }

        return PageContentProposal::create([
            'page_id' => $page->id,
            'corpus_document_id' => $document->id,
            'proposed_markdown' => $revised,
            'proposed_html_content' => TipTapContent::toDocument($revised),
            'summary' => $summary,
            'status' => PageContentProposal::STATUS_PENDING,
        ]);
    }

    /**
     * Guard every entry point: feature flag first (domain exception the UI
     * shows verbatim), then the provider key.
     */
    private function ensureEnabled(): void
    {
        if (! $this->isEnabled()) {
            throw new CopilotDisabledException;
        }

        if ((string) config('ai.providers.openrouter.key', '') === '') {
            throw new RuntimeException('مفتاح OpenRouter غير مضبوط — لا يمكن توليد الصفحات من المستندات.');
        }
    }

    /**
     * One authoring-tier generation with its exact provider cost recorded on
     * the spend ledger under the `authoring` feature.
     */
    private function generate(string $instructions, string $prompt): string
    {
        $this->ledger->clearContextCosts();

        try {
            $response = (new PageAuthoringAgent($instructions))->prompt($prompt);
        } finally {
            $this->recordSpend($response ?? null);
        }

        return trim((string) $response->text);
    }

    private function recordSpend(?\Laravel\Ai\Responses\AgentResponse $response): void
    {
        try {
            $this->ledger->record(
                self::FEATURE,
                (string) config('ai.authoring.model', 'deepseek/deepseek-v4-pro'),
                $response?->usage,
                $this->ledger->captureContextCosts(),
            );
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Parse the decision JSON, tolerating a stray markdown code fence but
     * nothing else.
     *
     * @return array{decision: string, page_id: int|null}
     */
    private function decodeDecision(string $raw): array
    {
        $json = trim((string) preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $raw));

        $decoded = json_decode($json, true);

        $decision = is_array($decoded) ? (string) ($decoded['decision'] ?? '') : '';
        $pageId = is_array($decoded) && is_numeric($decoded['page_id'] ?? null) ? (int) $decoded['page_id'] : null;

        if ($decision === 'new') {
            return ['decision' => 'new', 'page_id' => null];
        }

        if ($decision === 'update' && $pageId !== null) {
            return ['decision' => 'update', 'page_id' => $pageId];
        }

        throw new RuntimeException('أعاد النموذج قراراً غير صالح — حاول مرة أخرى.');
    }

    /**
     * Split the drafted markdown into the page title (its leading H1, when
     * the model followed the format) and the body content.
     *
     * @return array{0: string, 1: string}
     */
    private function splitTitle(string $markdown, string $fallbackTitle): array
    {
        $markdown = trim($markdown);

        if (preg_match('/^#\s+(.+?)\s*\n+(.*)$/su', $markdown, $matches) === 1 && trim($matches[1]) !== '') {
            return [trim($matches[1]), trim($matches[2])];
        }

        return [$fallbackTitle, $markdown];
    }

    /**
     * The document markdown a drafting prompt may carry, capped so a huge
     * extraction cannot blow up the input cost.
     */
    private function documentMarkdown(CorpusDocument $document): string
    {
        $markdown = trim((string) $document->extracted_markdown);

        if (mb_strlen($markdown) > self::MAX_DOCUMENT_CHARS) {
            $markdown = mb_substr($markdown, 0, self::MAX_DOCUMENT_CHARS)."\n\n…[اقتُطع باقي النص]";
        }

        return $markdown;
    }

    /**
     * Top-level blocks of a TipTap document that contain customBlock or
     * alert nodes — the frozen custom-block contract content the model never
     * sees and applying must keep byte-identical.
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

    /**
     * Slug parity with PageController::generateUniqueSlug() and the admin
     * assistant's executor: Latin transliteration plus a numeric suffix on
     * collision; trashed pages count because the column is unique.
     */
    private function generateUniqueSlug(string $title): string
    {
        $baseSlug = '/'.Str::slug($title);
        $slug = $baseSlug;
        $counter = 1;

        while (Page::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }
}
