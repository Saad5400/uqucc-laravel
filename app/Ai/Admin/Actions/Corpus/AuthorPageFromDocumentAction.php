<?php

namespace App\Ai\Admin\Actions\Corpus;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Ai\Authoring\PageAuthor;
use App\Jobs\Ai\AuthorPageFromDocumentJob;
use App\Models\Corpus\CorpusDocument;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Saad\AiKit\Approvals\Classified\Field;
use Saad\AiKit\Safety\BudgetGuard;

/**
 * Queue a document → page authoring run: {@see PageAuthor} turns one extracted
 * corpus document into either a NEW unpublished draft page or a review-gated
 * UPDATE PROPOSAL for an existing page — never a change to live content. The
 * outcome (draft page or pending proposal) is reviewed and applied separately.
 *
 * Gated on the same feature as the page copilot (honouring the master AI kill
 * switch): a disabled feature is refused with its reason before anything else.
 * Mirrors {@see \App\Http\Controllers\Manage\PageAuthoringController::store()},
 * dispatching {@see AuthorPageFromDocumentJob} after flipping the row to
 * authoring status «queued».
 */
class AuthorPageFromDocumentAction extends AdminAction
{
    public function __construct(
        private readonly PageAuthor $author,
        private readonly BudgetGuard $budget,
    ) {}

    public function name(): string
    {
        return 'author_page_from_document';
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
        return 'Queue an AI authoring run that turns an extracted corpus document into a draft page for review — '
            .'either a new unpublished draft page or a pending update proposal for an existing page, never a live change '
            .'Requires the document to be fully extracted (status "ready"). '
            .'Provide document_id from list_corpus_documents.';
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input, User $user): array
    {
        if (! $this->author->isEnabled()) {
            throw new AdminActionException($this->author->disabledReason() ?? 'توليد الصفحات من المستندات غير متاح حالياً.');
        }

        $document = CorpusDocument::query()->find((int) ($input['document_id'] ?? 0));

        if ($document === null) {
            throw new AdminActionException('لا يوجد مستند بهذا المعرّف. استخدم list_corpus_documents للتأكد.');
        }

        if ($document->status !== CorpusDocument::STATUS_READY || blank($document->extracted_markdown)) {
            throw new AdminActionException('لا يمكن توليد صفحة قبل اكتمال استخراج نص المستند.');
        }

        if (in_array($document->authoring_status, [CorpusDocument::AUTHORING_QUEUED, CorpusDocument::AUTHORING_RUNNING], true)) {
            throw new AdminActionException('يوجد توليد قيد التنفيذ لهذا المستند بالفعل.');
        }

        if ($this->budget->exceeded()) {
            throw new AdminActionException(__('ai-kit::safety.budget_exceeded'));
        }

        return ['document_id' => $document->id, 'document_title' => $document->title];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function summarize(array $normalized, User $user): string
    {
        return 'توليد صفحة من مستند «'.$normalized['document_title'].'» (يُنشئ مسودة/اقتراحاً للمراجعة).';
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

        $document->update([
            'authoring_status' => CorpusDocument::AUTHORING_QUEUED,
            'authoring_error' => null,
        ]);

        AuthorPageFromDocumentJob::dispatch($document->id);

        return ActionResult::text('تمت جدولة توليد الصفحة من المستند «'.$document->title.'» — ستظهر النتيجة (مسودة أو اقتراح) عند الاكتمال.');
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'document_id' => $schema->integer()
                ->description('The id of the extracted corpus document to author a page from, from list_corpus_documents.')
                ->required(),
        ];
    }

    /**
     * Arabic labels for the approval card, with each field's widget restated
     * alongside its label. Declaring a spec REPLACES the kit's value-based
     * inference for that argument, so the widget an unlabelled field would
     * have been given has to be named here — an id declared without
     * `Field::readonly` would come back as an editable text box.
     *
     * @return array<string, mixed>
     */
    public function fieldWidgets(): array
    {
        return [
            'document_id' => Field::readonly('document_id', label: 'المستند'),
        ];
    }
}
