<?php

namespace App\Http\Controllers\Manage;

use App\Ai\Copilot\TipTapContent;
use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\PageChangeRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The review queue for edits submitted by review-mode editors. A pending
 * request holds the exact partial payload the editor tried to save; approving
 * replays it against the live page through Eloquent (so `Page::booted()` cache
 * flushes fire), rejecting discards it. Gated on the `review-changes` gate — a
 * panel user who is not themselves in review mode.
 *
 * @see \App\Models\PageChangeRequest
 */
class PageChangeRequestController extends Controller
{
    /**
     * Human-readable Arabic labels for each editable page field, keyed by the
     * payload key {@see \App\Http\Requests\Manage\UpdatePageRequest} produces.
     *
     * @var array<string, string>
     */
    private const FIELD_LABELS = [
        'title' => 'العنوان',
        'slug' => 'الرابط',
        'parent_id' => 'الصفحة الأب',
        'icon' => 'الأيقونة',
        'hidden' => 'مخفية من الموقع',
        'hidden_from_bot' => 'مخفية من البوت',
        'smart_search' => 'البحث الذكي',
        'requires_prefix' => 'يتطلب كلمة «دليل»',
        'html_content' => 'المحتوى',
        'quick_response_message' => 'نص الرد السريع',
        'quick_response_buttons' => 'أزرار الرد السريع',
        'quick_response_attachments' => 'مرفقات الرد السريع',
        'quick_response_auto_extract_message' => 'استخراج نص الرد تلقائياً',
        'quick_response_auto_extract_buttons' => 'استخراج الأزرار تلقائياً',
        'quick_response_auto_extract_attachments' => 'استخراج المرفقات تلقائياً',
        'quick_response_send_link' => 'إرسال الرابط',
        'quick_response_send_screenshot' => 'إرسال لقطة الشاشة',
    ];

    /**
     * The pending queue, plus the recently decided requests for context.
     */
    public function index(): Response
    {
        return Inertia::render('manage/reviews/Index', [
            'pending' => PageChangeRequest::query()
                ->where('status', PageChangeRequest::STATUS_PENDING)
                ->with(['page', 'author'])
                ->latest('updated_at')
                ->get()
                ->map(fn (PageChangeRequest $request): array => [
                    'id' => $request->id,
                    'page' => $request->page === null ? null : [
                        'id' => $request->page->id,
                        'title' => $request->page->title,
                        'trashed' => $request->page->trashed(),
                    ],
                    'author_name' => $request->author?->name,
                    'changed_fields' => $this->changedFieldLabels($request),
                    'created_at' => $request->created_at?->toISOString(),
                    'updated_at' => $request->updated_at?->toISOString(),
                ])
                ->values(),
        ]);
    }

    /**
     * The review screen: a field-by-field diff of the page's current values
     * against the proposed ones, with approve/reject.
     */
    public function show(PageChangeRequest $changeRequest): Response
    {
        $changeRequest->load(['page', 'author', 'reviewer']);

        return Inertia::render('manage/reviews/Show', [
            'change' => [
                'id' => $changeRequest->id,
                'status' => $changeRequest->status,
                'author_name' => $changeRequest->author?->name,
                'reviewer_name' => $changeRequest->reviewer?->name,
                'review_note' => $changeRequest->review_note,
                'created_at' => $changeRequest->created_at?->toISOString(),
                'reviewed_at' => $changeRequest->reviewed_at?->toISOString(),
                'page' => $changeRequest->page === null ? null : [
                    'id' => $changeRequest->page->id,
                    'title' => $changeRequest->page->title,
                    'slug' => $changeRequest->page->slug,
                    'trashed' => $changeRequest->page->trashed(),
                ],
                'changes' => $this->changes($changeRequest),
            ],
        ]);
    }

    /**
     * Approve: replay the payload against the live page through Eloquent, then
     * mark the request approved. Re-application can still fail (e.g. a slug the
     * payload proposes was taken by another page since submission) — that comes
     * back as a flash error and the request stays pending.
     */
    public function approve(PageChangeRequest $changeRequest): RedirectResponse
    {
        if (! $changeRequest->isPending()) {
            return back()->with('error', 'هذا التعديل لم يعد بانتظار المراجعة.');
        }

        if ($changeRequest->page === null) {
            return back()->with('error', 'الصفحة المستهدفة لم تعد موجودة — لا يمكن اعتماد التعديل.');
        }

        try {
            DB::transaction(function () use ($changeRequest): void {
                $changeRequest->page->update($changeRequest->payload);

                $changeRequest->update([
                    'status' => PageChangeRequest::STATUS_APPROVED,
                    'reviewed_by' => auth()->id(),
                    'reviewed_at' => now(),
                ]);
            });
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', 'تعذر اعتماد التعديل. ربما تغيّرت الصفحة منذ إرسال التعديل.');
        }

        return to_route('manage.reviews.index')->with('success', 'تم اعتماد التعديل ونشره على الصفحة.');
    }

    /**
     * Reject: discard the request; the page is untouched.
     */
    public function reject(PageChangeRequest $changeRequest): RedirectResponse
    {
        if (! $changeRequest->isPending()) {
            return back()->with('error', 'هذا التعديل لم يعد بانتظار المراجعة.');
        }

        $changeRequest->update([
            'status' => PageChangeRequest::STATUS_REJECTED,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        return to_route('manage.reviews.index')->with('success', 'تم رفض التعديل — لم تتغيّر الصفحة.');
    }

    /**
     * The labels of the fields this request changes, for the queue summary.
     *
     * @return array<int, string>
     */
    private function changedFieldLabels(PageChangeRequest $request): array
    {
        return collect($request->payload)
            ->keys()
            ->map(fn (string $key): string => self::FIELD_LABELS[$key] ?? $key)
            ->values()
            ->all();
    }

    /**
     * A field-by-field diff of the target page's current values against the
     * proposed payload. `html_content` renders as markdown (two columns);
     * everything else renders as text/boolean.
     *
     * @return array<int, array{key: string, label: string, type: string, old: mixed, new: mixed}>
     */
    private function changes(PageChangeRequest $request): array
    {
        $page = $request->page;

        return collect($request->payload)
            ->map(function (mixed $new, string $key) use ($page): array {
                $current = $page?->getAttribute($key);

                if ($key === 'html_content') {
                    return [
                        'key' => $key,
                        'label' => self::FIELD_LABELS[$key],
                        'type' => 'markdown',
                        'old' => TipTapContent::toMarkdown($page?->html_content),
                        'new' => TipTapContent::toMarkdown($new),
                    ];
                }

                if (is_bool($new)) {
                    return [
                        'key' => $key,
                        'label' => self::FIELD_LABELS[$key] ?? $key,
                        'type' => 'bool',
                        'old' => (bool) $current,
                        'new' => $new,
                    ];
                }

                return [
                    'key' => $key,
                    'label' => self::FIELD_LABELS[$key] ?? $key,
                    'type' => 'text',
                    'old' => $this->scalarize($key, $current, $page),
                    'new' => $this->scalarize($key, $new, $page),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Render a non-content field value as a display string.
     */
    private function scalarize(string $key, mixed $value, ?Page $page): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if ($key === 'parent_id') {
            return Page::withTrashed()->find($value)?->title ?? '—';
        }

        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }

        return (string) $value;
    }
}
