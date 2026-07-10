<?php

namespace App\Http\Controllers\Manage;

use App\Ai\Authoring\PageAuthor;
use App\Ai\Copilot\TipTapContent;
use App\Ai\Spend\SpendLedger;
use App\Http\Controllers\Controller;
use App\Jobs\Ai\AuthorPageFromDocumentJob;
use App\Models\Ai\PageContentProposal;
use App\Models\Corpus\CorpusDocument;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Document → page authoring endpoints: trigger a queued authoring run for an
 * extracted corpus document, and review/apply/reject the content proposals it
 * produces for existing pages. Review-first throughout — a run only ever
 * creates an UNPUBLISHED draft page or a pending proposal; applying navigates
 * to the page editor for the final human pass before publishing.
 *
 * Gated on the admin_copilot feature (honouring the master AI kill switch):
 * a disabled feature answers 403 before any other outcome, matching
 * {@see PageCopilotController}; the UI disables its buttons with the reason.
 */
class PageAuthoringController extends Controller
{
    public function __construct(
        private readonly PageAuthor $author,
        private readonly SpendLedger $ledger,
    ) {}

    /**
     * POST /manage/corpus/{document}/author (name: manage.corpus.author) —
     * queue an authoring run. Pre-flight refusals (not extracted, already
     * running, budget exhausted) come back as flash errors the panel toasts.
     */
    public function store(CorpusDocument $document): RedirectResponse
    {
        abort_unless($this->author->isEnabled(), 403, $this->author->disabledReason() ?? 'توليد الصفحات من المستندات غير متاح حالياً.');

        if ($document->status !== CorpusDocument::STATUS_READY || blank($document->extracted_markdown)) {
            return back()->with('error', 'لا يمكن توليد صفحة قبل اكتمال استخراج نص المستند.');
        }

        if (in_array($document->authoring_status, [CorpusDocument::AUTHORING_QUEUED, CorpusDocument::AUTHORING_RUNNING], true)) {
            return back()->with('error', 'يوجد توليد قيد التنفيذ لهذا المستند بالفعل.');
        }

        if (! $this->ledger->hasBudgetRemaining()) {
            return back()->with('error', $this->ledger->budgetExhaustedMessage());
        }

        $document->update([
            'authoring_status' => CorpusDocument::AUTHORING_QUEUED,
            'authoring_error' => null,
        ]);

        AuthorPageFromDocumentJob::dispatch($document->id);

        return back()->with('success', 'تمت جدولة توليد الصفحة من المستند — ستظهر النتيجة هنا عند الاكتمال.');
    }

    /**
     * GET /manage/corpus/proposals/{proposal}
     * (name: manage.corpus.proposals.show) — the review screen: the proposed
     * content next to the page's CURRENT content, with apply/reject.
     */
    public function show(PageContentProposal $proposal): Response
    {
        $proposal->load(['page', 'document']);

        return Inertia::render('manage/corpus/ProposalReview', [
            'proposal' => [
                'id' => $proposal->id,
                'status' => $proposal->status,
                'summary' => $proposal->summary,
                'error' => $proposal->error,
                'proposed_markdown' => $proposal->proposed_markdown,
                'created_at' => $proposal->created_at?->toISOString(),
                'applied_at' => $proposal->applied_at?->toISOString(),
                'page' => $proposal->page === null ? null : [
                    'id' => $proposal->page->id,
                    'title' => $proposal->page->title,
                    'slug' => $proposal->page->slug,
                    'trashed' => $proposal->page->trashed(),
                    'current_markdown' => TipTapContent::toMarkdown($proposal->page->html_content),
                ],
                'document' => [
                    'id' => $proposal->document->id,
                    'title' => $proposal->document->title,
                ],
            ],
            'authoring' => [
                'enabled' => $this->author->isEnabled(),
                'disabled_reason' => $this->author->disabledReason(),
            ],
        ]);
    }

    /**
     * POST /manage/corpus/proposals/{proposal}/apply
     * (name: manage.corpus.proposals.apply) — apply the pending proposal to
     * its page (Eloquent write; model events fire), then open the page editor
     * for the final human review before publish.
     */
    public function apply(PageContentProposal $proposal): RedirectResponse
    {
        abort_unless($this->author->isEnabled(), 403, $this->author->disabledReason() ?? 'توليد الصفحات من المستندات غير متاح حالياً.');

        if (! $proposal->isPending()) {
            return back()->with('error', 'هذا الاقتراح لم يعد بانتظار المراجعة.');
        }

        $proposal = $this->author->applyProposal($proposal);

        if ($proposal->status !== PageContentProposal::STATUS_APPLIED) {
            return back()->with('error', $proposal->error ?? 'تعذر تطبيق الاقتراح.');
        }

        return to_route('manage.pages.edit', $proposal->page_id)
            ->with('success', 'تم تطبيق الاقتراح على الصفحة — راجع المحتوى ثم احفظه وانشره.');
    }

    /**
     * POST /manage/corpus/proposals/{proposal}/reject
     * (name: manage.corpus.proposals.reject) — decline the proposal; the
     * page is untouched.
     */
    public function reject(PageContentProposal $proposal): RedirectResponse
    {
        abort_unless($this->author->isEnabled(), 403, $this->author->disabledReason() ?? 'توليد الصفحات من المستندات غير متاح حالياً.');

        if (! $proposal->isPending()) {
            return back()->with('error', 'هذا الاقتراح لم يعد بانتظار المراجعة.');
        }

        $this->author->rejectProposal($proposal);

        return redirect()
            ->route('manage.corpus.edit', $proposal->corpus_document_id)
            ->with('success', 'تم رفض الاقتراح — لم تتغير الصفحة.');
    }
}
