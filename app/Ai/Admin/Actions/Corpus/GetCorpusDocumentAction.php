<?php

namespace App\Ai\Admin\Actions\Corpus;

use App\Ai\Admin\Actions\ActionResult;
use App\Ai\Admin\Actions\AdminAction;
use App\Ai\Admin\Actions\AdminActionException;
use App\Models\Corpus\CorpusDocument;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;

/**
 * A single corpus document's workspace view — its metadata, extraction and
 * authoring lifecycle, any error, and a truncated preview of the extracted
 * markdown. Read-only. Mirrors the payload of
 * {@see \App\Http\Controllers\Manage\CorpusDocumentController::edit()}.
 */
class GetCorpusDocumentAction extends AdminAction
{
    /** Character cap on the extracted-markdown preview returned to the model. */
    private const PREVIEW_CHARS = 2000;

    public function name(): string
    {
        return 'get_corpus_document';
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
        return 'Show one AI knowledge-base document by id: its title, extraction status, authoring status, any error, '
            .'reference url, the id of any authored page, and a truncated preview of its extracted markdown '
            .'(عرض مستند واحد من قاعدة المعرفة بمعرّفه مع حالته ومقتطف من نصه المستخرج). '
            .'Provide document_id from list_corpus_documents. Read-only.';
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

        return ['document_id' => $document->id];
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    protected function run(array $normalized, User $user): ActionResult
    {
        $document = CorpusDocument::query()->with('corpusItem')->find((int) $normalized['document_id']);

        if ($document === null) {
            throw new AdminActionException('المستند المطلوب لم يعد موجوداً.');
        }

        $retrieval = match (true) {
            $document->corpusItem === null => 'غير مفهرس',
            $document->corpusItem->enabled => 'مفعّل في البحث الذكي',
            default => 'معطّل (لن يظهر في البحث الذكي)',
        };

        $markdown = trim((string) $document->extracted_markdown);

        $preview = $markdown === ''
            ? '— (لا يوجد نص مستخرج بعد)'
            : Str::limit($markdown, self::PREVIEW_CHARS, '… [اقتُطع باقي النص]');

        $lines = [
            'المعرّف: '.$document->id,
            'العنوان: '.$document->title,
            'اسم الملف الأصلي: '.$document->original_filename,
            'النوع: '.$document->fileKind(),
            'الحجم: '.($document->size !== null ? $document->size.' بايت' : '—'),
            'حالة الاستخراج: '.$document->status,
            'حالة البحث الذكي: '.$retrieval,
            'حالة التوليد: '.($document->authoring_status ?? '—'),
            'خطأ الاستخراج: '.(filled($document->error) ? $document->error : '—'),
            'خطأ التوليد: '.(filled($document->authoring_error) ? $document->authoring_error : '—'),
            'رابط المرجع: '.(filled($document->reference_url) ? $document->reference_url : '—'),
            'الصفحة المولّدة: '.($document->authored_page_id !== null ? '#'.$document->authored_page_id : '—'),
        ];

        return ActionResult::text(
            implode("\n", $lines)."\n\n"
            ."مقتطف من النص المستخرج:\n".$preview,
        );
    }

    /**
     * @return array<string, \Illuminate\JsonSchema\Types\Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'document_id' => $schema->integer()
                ->description('The id of the corpus document to inspect, from list_corpus_documents.')
                ->required(),
        ];
    }
}
