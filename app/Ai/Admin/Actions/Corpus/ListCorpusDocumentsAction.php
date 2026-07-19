<?php

namespace App\Ai\Admin\Actions\Corpus;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Models\Corpus\CorpusDocument;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * The operator's view of the AI knowledge-base documents — every uploaded
 * regulation, guide and pasted note feeding the retrieval corpus, newest first,
 * with the ids the other corpus actions need and each document's extraction and
 * authoring lifecycle. Read-only. Mirrors the listing of
 * {@see \App\Http\Controllers\Manage\CorpusDocumentController::index()}.
 */
class ListCorpusDocumentsAction extends AdminAction
{
    public function name(): string
    {
        return 'list_corpus_documents';
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
        return 'corpus';
    }

    public function description(): string
    {
        return 'List the AI knowledge-base (corpus) documents with each document\'s id, title, extraction status, '
            .'authoring status, file kind/size, reference url and the id of any page authored from it '
            .'(عرض مستندات قاعدة المعرفة للذكاء الاصطناعي بمعرفاتها وحالة استخراجها وتوليد الصفحات منها وروابطها). '
            .'Use the returned ids for get_corpus_document, reextract_corpus_document, reingest_corpus_document '
            .'and author_page_from_document. Read-only.';
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        $documents = CorpusDocument::query()
            ->latest('created_at')
            ->latest('id')
            ->get();

        if ($documents->isEmpty()) {
            return ActionResult::text('لا توجد مستندات في قاعدة المعرفة بعد.');
        }

        $lines = $documents->map(fn (CorpusDocument $document): string => sprintf(
            '- [%d] %s | النوع: %s | الحجم: %s | الحالة: %s | التوليد: %s | مرجع: %s | صفحة مولّدة: %s',
            $document->id,
            $document->title,
            $document->fileKind(),
            $document->size !== null ? $document->size.' بايت' : '—',
            $document->status,
            $document->authoring_status ?? '—',
            filled($document->reference_url) ? $document->reference_url : '—',
            $document->authored_page_id !== null ? '#'.$document->authored_page_id : '—',
        ));

        return ActionResult::text(
            "مستندات قاعدة المعرفة (id | العنوان | النوع | الحجم | الحالة | التوليد | المرجع | الصفحة المولّدة):\n"
            .$lines->implode("\n"),
        );
    }
}
