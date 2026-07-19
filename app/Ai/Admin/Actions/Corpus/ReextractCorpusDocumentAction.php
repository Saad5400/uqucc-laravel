<?php

namespace App\Ai\Admin\Actions\Corpus;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Jobs\Ai\ExtractCorpusDocumentJob;
use App\Models\Corpus\CorpusDocument;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Queue a re-extraction of a corpus document — re-run the PDF text layer or the
 * vision model over the stored file, followed by re-indexing. Replaces any
 * manual edits to the extracted markdown. Mirrors
 * {@see \App\Http\Controllers\Manage\CorpusDocumentController::reextract()},
 * dispatching {@see ExtractCorpusDocumentJob}.
 */
class ReextractCorpusDocumentAction extends AdminAction
{
    public function name(): string
    {
        return 'reextract_corpus_document';
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
        return 'Queue a re-extraction of a corpus document\'s text (PDF text layer or vision model) then re-indexing '
            .'(جدولة إعادة استخراج نص المستند ثم إعادة فهرسته). '
            .'Warning: this replaces any manual edits to the extracted markdown. '
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

        return ['document_id' => $document->id, 'document_title' => $document->title];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function summarize(array $normalized, User $user): string
    {
        return 'إعادة استخراج نص المستند «'.$normalized['document_title'].'» (يستبدل أي تعديلات يدوية على النص المستخرج).';
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

        ExtractCorpusDocumentJob::dispatch($document->id);

        return ActionResult::text('تمت جدولة إعادة استخراج المستند «'.$document->title.'».');
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'document_id' => $schema->integer()
                ->description('The id of the corpus document to re-extract, from list_corpus_documents.')
                ->required(),
        ];
    }
}
