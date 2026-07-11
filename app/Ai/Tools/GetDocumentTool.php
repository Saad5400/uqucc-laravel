<?php

namespace App\Ai\Tools;

use App\Ai\Tools\Concerns\GatedByAiSettings;
use App\Models\Corpus\CorpusDocument;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Fetches the full extracted text of one READY uploaded corpus document
 * (official regulations and rules PDFs) by its numeric id — the document
 * counterpart of get_page. Without it the assistant can only see search
 * snippets of a document and thrashes get_page trying to read the rest.
 * Read-only.
 */
class GetDocumentTool implements Tool
{
    use GatedByAiSettings;

    /** Keep even the longest regulations within a sane prompt budget. */
    private const MAX_CHARS = 60000;

    public function description(): Stringable|string
    {
        return 'Fetch the full extracted text of an uploaded corpus document — official regulations and rules PDFs — by its numeric id '
            .'(جلب النص الكامل لمستند مرفوع مثل اللوائح والقواعد الرسمية بمعرفه الرقمي). '
            .'Use the id from search_content results marked "(document: {id})". Read-only.';
    }

    public function handle(Request $request): Stringable|string
    {
        if ($this->aiToolsAreDisabled()) {
            return $this->aiDisabledReply();
        }

        $id = $request->integer('document');

        $document = CorpusDocument::query()
            ->whereKey($id)
            ->where('status', CorpusDocument::STATUS_READY)
            ->first();

        if ($document === null) {
            return "لم يتم العثور على مستند بالمعرف \"{$id}\". No ready document matches this id — use search_content to find one.";
        }

        $updated = $document->updated_at !== null
            ? 'آخر تحديث: '.$document->updated_at->toDateString()."\n"
            : '';

        return Str::limit(trim((string) $document->extracted_markdown), self::MAX_CHARS, "\n[اقتُطع باقي المستند لطوله]")
            ."\n\n---\n{$updated}document: {$document->id} — {$document->title}";
    }

    /**
     * Get the tool's schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'document' => $schema->integer()
                ->description('The numeric document id, e.g. 3 from a search result marked "(document: 3)".')
                ->required(),
        ];
    }
}
