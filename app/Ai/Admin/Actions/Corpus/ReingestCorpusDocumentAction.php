<?php

namespace App\Ai\Admin\Actions\Corpus;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Jobs\Ai\IngestDocumentJob;
use App\Models\Corpus\CorpusDocument;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Queue a re-chunk and re-embed of a corpus document's current extracted
 * markdown WITHOUT re-running the extraction step. Refused before extraction is
 * complete. Mirrors
 * {@see \App\Http\Controllers\Manage\CorpusDocumentController::reingest()},
 * dispatching {@see IngestDocumentJob}.
 */
class ReingestCorpusDocumentAction extends AdminAction
{
    public function name(): string
    {
        return 'reingest_corpus_document';
    }

    public function requiredAbility(): ?string
    {
        return 'edit-content';
    }

    public function category(): string
    {
        return 'corpus';
    }

    public function description(): string
    {
        return 'Queue a re-chunk and re-embed of a corpus document\'s current extracted markdown, without re-running '
            .'extraction (جدولة إعادة فهرسة النص المستخرج الحالي للمستند دون إعادة استخراجه). '
            .'Only allowed once extraction is complete (status «ready»). '
            .'Provide document_id from list_corpus_documents.';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input, User $user): array
    {
        $document = CorpusDocument::query()->find((int) ($input['document_id'] ?? 0));

        if ($document === null) {
            throw new AdminActionException('لا يوجد مستند بهذا المعرّف. استخدم list_corpus_documents للتأكد.');
        }

        if ($document->status !== CorpusDocument::STATUS_READY || blank($document->extracted_markdown)) {
            throw new AdminActionException('لا يمكن إعادة الفهرسة قبل اكتمال استخراج النص.');
        }

        return ['document_id' => $document->id, 'document_title' => $document->title];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function summarize(array $normalized, User $user): string
    {
        return 'إعادة فهرسة النص المستخرج للمستند «'.$normalized['document_title'].'» (دون إعادة استخراجه).';
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        $document = CorpusDocument::query()->find((int) $normalized['document_id']);

        if ($document === null) {
            throw new AdminActionException('المستند المطلوب لم يعد موجوداً.');
        }

        if ($document->status !== CorpusDocument::STATUS_READY || blank($document->extracted_markdown)) {
            throw new AdminActionException('لا يمكن إعادة الفهرسة قبل اكتمال استخراج النص.');
        }

        IngestDocumentJob::dispatch($document->id);

        return ActionResult::text('تمت جدولة إعادة فهرسة المستند «'.$document->title.'».');
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'document_id' => $schema->integer()
                ->description('The id of the corpus document to re-ingest, from list_corpus_documents.')
                ->required(),
        ];
    }
}
