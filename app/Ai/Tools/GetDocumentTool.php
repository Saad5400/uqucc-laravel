<?php

namespace App\Ai\Tools;

use App\Ai\Corpus\DocumentSection;
use App\Ai\Corpus\DocumentSectioner;
use App\Ai\Tools\Concerns\GatedByAiSettings;
use App\Models\Corpus\CorpusDocument;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Reads one READY uploaded corpus document (official regulations and rules
 * PDFs) by its numeric id — the document counterpart of get_page — without
 * ever flooding the prompt with a whole manual.
 *
 * Small documents return in full. A large document instead returns its
 * numbered table of contents; the model then calls again with `section`
 * (optionally `end_section` for a contiguous range) to read just the parts it
 * needs — typically the section whose heading matched in search_content.
 * Read-only.
 */
class GetDocumentTool implements Tool
{
    use GatedByAiSettings;

    /** Documents at or under this many characters skip the outline and return whole. */
    private const FULL_DOCUMENT_CHARS = 20000;

    /** Cap on any single reply (full doc fast-path aside), so a huge section range stays sane. */
    private const MAX_REPLY_CHARS = 30000;

    public function __construct(private readonly DocumentSectioner $sectioner) {}

    public function description(): Stringable|string
    {
        return 'Read an uploaded corpus document — official regulations, rules and guides PDFs — by its numeric id '
            .'(قراءة مستند مرفوع مثل اللوائح والأدلة الرسمية بمعرفه الرقمي). '
            .'Use the id from search_content results marked "(document: {id})". '
            .'Short documents return in full. Long documents return a numbered table of contents (فهرس) instead — '
            .'call again with `section` (and optionally `end_section`) to read specific sections; the section heading '
            .'from a search_content snippet tells you which section to fetch. Read-only.';
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

        $markdown = trim((string) $document->extracted_markdown);

        if (mb_strlen($markdown) <= self::FULL_DOCUMENT_CHARS) {
            return $markdown.$this->footer($document);
        }

        $sections = $this->sectioner->sections($markdown);

        if ($request->integer('section') > 0) {
            return $this->sectionsReply($document, $sections, $request);
        }

        return $this->outlineReply($document, $sections);
    }

    /**
     * The table of contents of a large document: every section number with
     * its heading and approximate length, plus how to fetch sections.
     *
     * @param  list<DocumentSection>  $sections
     */
    private function outlineReply(CorpusDocument $document, array $sections): string
    {
        $lines = array_map(function (DocumentSection $section): string {
            $label = match (true) {
                $section->continuation => '… تكملة القسم السابق',
                $section->heading === null => '(مقدمة بلا عنوان)',
                default => str_repeat('#', max(1, $section->level)).' '.$section->heading,
            };

            return sprintf('%d. %s (≈%d كلمة)', $section->number, $label, $section->wordCount());
        }, $sections);

        $total = count($sections);

        return "المستند طويل، لذا هذا فهرس أقسامه بدل النص الكامل — هذه القائمة ليست نص المستند.\n"
            ."This document is long, so here is its table of contents instead of the full text.\n"
            ."Call get_document again with `section` (1-{$total}) — and optionally `end_section` — to read the sections you need.\n\n"
            ."فهرس المستند ({$total} قسماً):\n"
            .implode("\n", $lines)
            .$this->footer($document);
    }

    /**
     * One section or a contiguous range of sections, verbatim, capped at
     * MAX_REPLY_CHARS.
     *
     * @param  list<DocumentSection>  $sections
     */
    private function sectionsReply(CorpusDocument $document, array $sections, Request $request): string
    {
        $total = count($sections);
        $start = $request->integer('section');

        if ($start < 1 || $start > $total) {
            return "لا يوجد قسم رقم {$start} — أرقام الأقسام الصالحة من 1 إلى {$total}. "
                .'Invalid section number; call get_document without `section` to see the table of contents.';
        }

        $end = min($total, max($start, $request->integer('end_section', $start)));

        $picked = array_slice($sections, $start - 1, $end - $start + 1);

        $body = implode("\n\n", array_map(
            fn (DocumentSection $section): string => $section->content,
            $picked,
        ));

        $truncated = mb_strlen($body) > self::MAX_REPLY_CHARS;

        if ($truncated) {
            $body = Str::limit($body, self::MAX_REPLY_CHARS, "\n[اقتُطع باقي النطاق لطوله — اطلب أقساماً أقل في كل استدعاء]");
        }

        $range = $start === $end ? "القسم {$start}" : "الأقسام {$start}–{$end}";

        return "{$range} من {$total}:\n\n".$body.$this->footer($document);
    }

    private function footer(CorpusDocument $document): string
    {
        $updated = $document->updated_at !== null
            ? 'آخر تحديث: '.$document->updated_at->toDateString()."\n"
            : '';

        return "\n\n---\n{$updated}رابط المستند (المصدر): {$document->referenceUrl()}\ndocument: {$document->id} — {$document->title}";
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
            'section' => $schema->integer()
                ->description('1-based section number from the document\'s table of contents. Omit to get the full text of a short document or the table of contents of a long one.'),
            'end_section' => $schema->integer()
                ->description('Optional inclusive end of a contiguous section range starting at `section`.'),
        ];
    }
}
