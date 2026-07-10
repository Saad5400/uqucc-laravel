<?php

namespace App\Http\Controllers\Manage;

use App\Ai\Authoring\PageAuthor;
use App\Ai\Corpus\UploadedTextExtractor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\StoreCorpusDocumentRequest;
use App\Http\Requests\Manage\StoreCorpusTextRequest;
use App\Http\Requests\Manage\UpdateCorpusDocumentRequest;
use App\Jobs\Ai\ExtractCorpusDocumentJob;
use App\Jobs\Ai\IngestDocumentJob;
use App\Models\Ai\PageContentProposal;
use App\Models\Corpus\CorpusDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class CorpusDocumentController extends Controller
{
    /**
     * List the AI corpus documents: latest first, 25 per page, filterable by
     * extraction status and searchable by title. The client polls for
     * freshness while extractions run.
     */
    public function index(Request $request, PageAuthor $author): Response
    {
        $documents = CorpusDocument::query()
            ->with(['uploader', 'corpusItem', 'authoredPage', 'contentProposals.page'])
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('search'), fn (Builder $query) => $query->where('title', 'like', '%'.$request->string('search')->toString().'%'))
            ->latest('created_at')
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (CorpusDocument $document): array => [
                'id' => $document->id,
                'title' => $document->title,
                'original_filename' => $document->original_filename,
                'kind' => $document->fileKind(),
                'size' => $document->size,
                'status' => $document->status,
                'error' => $document->error,
                'index_status' => $document->corpusItem?->status,
                'has_markdown' => filled($document->extracted_markdown),
                'uploader_name' => $document->uploader?->name,
                'created_at' => $document->created_at?->toISOString(),
                ...$this->authoringPayload($document),
            ]);

        return Inertia::render('manage/corpus/Index', [
            'documents' => $documents,
            'filters' => [
                'status' => $request->query('status'),
                'search' => $request->query('search'),
            ],
            'authoring' => [
                'enabled' => $author->isEnabled(),
                'disabled_reason' => $author->disabledReason(),
            ],
        ]);
    }

    /**
     * Store an uploaded document and queue text extraction. The mime and
     * size come from the stored bytes (not client-supplied values), then the
     * admin sees the row move through الحالة on the list page.
     */
    public function store(StoreCorpusDocumentRequest $request): RedirectResponse
    {
        $file = $request->file('file');

        $path = $file->store(CorpusDocument::DIRECTORY, CorpusDocument::DISK);

        $disk = Storage::disk(CorpusDocument::DISK);

        $document = CorpusDocument::query()->create([
            'title' => $request->validated('title'),
            'original_filename' => $file->getClientOriginalName(),
            'disk' => CorpusDocument::DISK,
            'path' => $path,
            'mime' => $disk->mimeType($path) ?: null,
            'size' => $disk->size($path),
            'status' => CorpusDocument::STATUS_PENDING,
            'uploaded_by' => $request->user()->id,
        ]);

        ExtractCorpusDocumentJob::dispatch($document->id);

        return back()->with('success', 'تم رفع المستند وجدولة استخراج النص.');
    }

    /**
     * Store pasted text as a normal corpus document: the text is written to
     * a .md file on the same disk/path scheme (so re-extract keeps working),
     * the extracted markdown is set immediately (no AI needed), and only
     * ingestion is queued.
     */
    public function storeText(StoreCorpusTextRequest $request): RedirectResponse
    {
        $content = trim(UploadedTextExtractor::normalizeText($request->validated('content')));

        $path = CorpusDocument::DIRECTORY.'/'.Str::random(40).'.md';

        Storage::disk(CorpusDocument::DISK)->put($path, $content);

        $document = CorpusDocument::query()->create([
            'title' => $request->validated('title'),
            'original_filename' => Str::limit($request->validated('title'), 100, '').'.md',
            'disk' => CorpusDocument::DISK,
            'path' => $path,
            'mime' => 'text/markdown',
            'size' => strlen($content),
            'status' => CorpusDocument::STATUS_READY,
            'extracted_markdown' => $content,
            'uploaded_by' => $request->user()->id,
        ]);

        IngestDocumentJob::dispatch($document->id);

        return back()->with('success', 'تمت إضافة النص وجدولة فهرسته.');
    }

    /**
     * Show a document's workspace: metadata plus the editable extracted
     * markdown.
     */
    public function edit(CorpusDocument $document, PageAuthor $author): Response
    {
        $document->load(['uploader', 'corpusItem', 'authoredPage', 'contentProposals.page']);

        return Inertia::render('manage/corpus/Edit', [
            'document' => [
                'id' => $document->id,
                'title' => $document->title,
                'original_filename' => $document->original_filename,
                'kind' => $document->fileKind(),
                'size' => $document->size,
                'status' => $document->status,
                'error' => $document->error,
                'index_status' => $document->corpusItem?->status,
                'extracted_markdown' => $document->extracted_markdown,
                'uploader_name' => $document->uploader?->name,
                'created_at' => $document->created_at?->toISOString(),
                ...$this->authoringPayload($document),
            ],
            'authoring' => [
                'enabled' => $author->isEnabled(),
                'disabled_reason' => $author->disabledReason(),
            ],
        ]);
    }

    /**
     * The authoring slice both screens share: lifecycle state plus links to
     * the run's outcome (the created draft page or the latest proposal).
     *
     * @return array<string, mixed>
     */
    private function authoringPayload(CorpusDocument $document): array
    {
        /** @var PageContentProposal|null $proposal */
        $proposal = $document->contentProposals->first();

        return [
            'authoring_status' => $document->authoring_status,
            'authoring_error' => $document->authoring_error,
            'authored_page' => $document->authoredPage === null ? null : [
                'id' => $document->authoredPage->id,
                'title' => $document->authoredPage->title,
            ],
            'latest_proposal' => $proposal === null ? null : [
                'id' => $proposal->id,
                'status' => $proposal->status,
                'page_title' => $proposal->page?->title,
            ],
        ];
    }

    /**
     * Save a manual correction. A change to the title or the extracted
     * markdown re-indexes the document automatically, so the corpus never
     * serves stale chunks of a text the admin just fixed.
     */
    public function update(UpdateCorpusDocumentRequest $request, CorpusDocument $document): RedirectResponse
    {
        $document->fill([
            'title' => $request->validated('title'),
            'extracted_markdown' => $request->validated('extracted_markdown'),
        ])->save();

        if ($document->wasChanged(['extracted_markdown', 'title'])) {
            IngestDocumentJob::dispatch($document->id);

            return back()->with('success', 'تم حفظ المستند وجدولة إعادة فهرسته.');
        }

        return back()->with('success', 'تم حفظ المستند.');
    }

    /**
     * Queue a re-extraction (text layer or vision model) followed by
     * re-indexing; replaces any manual edits to the extracted markdown.
     */
    public function reextract(CorpusDocument $document): RedirectResponse
    {
        ExtractCorpusDocumentJob::dispatch($document->id);

        return back()->with('success', 'تمت جدولة إعادة الاستخراج.');
    }

    /**
     * Queue a re-chunk + re-embed of the current extracted markdown without
     * re-running the extraction step.
     */
    public function reingest(CorpusDocument $document): RedirectResponse
    {
        if ($document->status !== CorpusDocument::STATUS_READY || blank($document->extracted_markdown)) {
            return back()->with('error', 'لا يمكن إعادة الفهرسة قبل اكتمال استخراج النص.');
        }

        IngestDocumentJob::dispatch($document->id);

        return back()->with('success', 'تمت جدولة إعادة الفهرسة.');
    }

    /**
     * Delete a document. The model's deleted hook removes the stored file
     * and evicts its chunks from the AI search index.
     */
    public function destroy(CorpusDocument $document): RedirectResponse
    {
        $document->delete();

        return redirect('/manage/corpus')->with('success', 'تم حذف المستند.');
    }
}
