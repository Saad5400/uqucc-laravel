<?php

namespace App\Ai\Corpus;

use App\Models\Corpus\CorpusDocument;
use App\Support\LocalFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Turns an uploaded corpus file into markdown text.
 *
 * Routing:
 *   - text  → the file contents ARE the text (txt / markdown uploads and
 *             pasted-text documents): read them directly, zero AI cost.
 *   - PDF   → try the text layer first (smalot/pdfparser, pure PHP, offline).
 *             A born-digital PDF resolves here with zero AI cost. When the
 *             layer is missing or too thin to be real content (a scan), fall
 *             through to the vision model.
 *   - image → straight to the vision model (no OCR ships with PHP).
 *
 * Empty output is an ERROR, not a success: a corpus document that yields no
 * text is useless for retrieval, so the caller gets a throw (recorded on the
 * row) instead of a silently empty item.
 */
class UploadedTextExtractor
{
    /**
     * A PDF text layer with fewer LETTERS AND DIGITS than this is treated as
     * unusable — page numbers, stray punctuation, or a font with broken
     * ToUnicode maps that extracts as whitespace/soft-hyphen soup — and the
     * document is routed to the vision model instead. Counting only \p{L}\p{N}
     * matters: a 14-page Arabic PDF with no usable maps yields tens of
     * thousands of junk characters and zero letters.
     */
    public const MIN_TEXT_LAYER_CHARS = 120;

    /**
     * Letters and digits must make up at least this fraction of the layer's
     * non-whitespace characters. A real document's text is mostly letters;
     * broken ToUnicode maps instead produce punctuation/soft-hyphen soup
     * sprinkled with mojibake letters (a real 14-page Arabic PDF measured
     * 5.5%) that clears the absolute floor but reads as rubbish.
     */
    public const MIN_MEANINGFUL_RATIO = 0.5;

    /**
     * When more than this fraction of a text layer's Arabic characters are
     * presentation-form glyphs (U+FB50–U+FDFF, U+FE70–U+FEFF), the extractor
     * dumped shaped glyphs rather than logical text — unreadable for search
     * and for the model — so the document is routed to the vision model.
     */
    public const MAX_PRESENTATION_FORM_RATIO = 0.2;

    public function __construct(private readonly DocumentVisionExtractor $vision) {}

    public function extract(CorpusDocument $document): string
    {
        $markdown = trim($this->extractRaw($document));

        if ($markdown === '') {
            throw new RuntimeException('لم يُستخرج أي نص من الملف — تحقق من أن الملف يحتوي نصاً مقروءاً.');
        }

        return $markdown;
    }

    /**
     * Normalize raw text for storage as extracted markdown: scrub invalid
     * UTF-8 byte sequences, strip a leading BOM, and unify line endings to
     * "\n". Shared by the text-file extraction branch and the paste flow.
     */
    public static function normalizeText(string $text): string
    {
        $text = (string) mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        if (str_starts_with($text, "\u{FEFF}")) {
            $text = substr($text, strlen("\u{FEFF}"));
        }

        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    private function extractRaw(CorpusDocument $document): string
    {
        if ($document->isText()) {
            return self::normalizeText(
                (string) Storage::disk($document->disk)->get($document->path)
            );
        }

        if ($document->isPdf()) {
            $file = LocalFile::from($document->disk, $document->path);
            $text = $this->fromPdfTextLayer($file->path);

            if ($this->isUsableTextLayer($text)) {
                return $text;
            }

            return $this->vision->extract($document);
        }

        if ($document->isImage()) {
            return $this->vision->extract($document);
        }

        throw new RuntimeException('نوع الملف غير مدعوم للاستخراج: '.($document->mime ?? 'غير معروف'));
    }

    /**
     * Best-effort text-layer read: a malformed PDF yields '' (routing to
     * vision) rather than aborting extraction.
     */
    private function fromPdfTextLayer(string $path): string
    {
        try {
            $text = (new Parser)->parseFile($path)->getText();
        } catch (Throwable) {
            return '';
        }

        $collapsed = (string) preg_replace('/[ \t]+/', ' ', $text);
        $collapsed = (string) preg_replace('/\s*\n\s*/', "\n", $collapsed);

        return trim($collapsed);
    }

    /**
     * Whether an extracted PDF text layer is real, logical-order content —
     * enough letters/digits, and not Arabic presentation-form glyph soup.
     */
    public function isUsableTextLayer(string $text): bool
    {
        preg_match_all('/[\p{L}\p{N}]/u', $text, $meaningful);
        $meaningfulCount = count($meaningful[0]);

        if ($meaningfulCount < self::MIN_TEXT_LAYER_CHARS) {
            return false;
        }

        $nonWhitespace = mb_strlen((string) preg_replace('/\s+/u', '', $text));

        if ($meaningfulCount / max(1, $nonWhitespace) < self::MIN_MEANINGFUL_RATIO) {
            return false;
        }

        preg_match_all('/[\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $text, $shaped);

        if ($shaped[0] !== []) {
            preg_match_all('/[\x{0600}-\x{06FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $text, $arabic);

            if (count($shaped[0]) / max(1, count($arabic[0])) > self::MAX_PRESENTATION_FORM_RATIO) {
                return false;
            }
        }

        return ! $this->looksLikeReversedArabic($text);
    }

    /**
     * Detects the other classic Arabic-PDF failure: logical codepoints dumped
     * in VISUAL (left-to-right) order, so every word reads backwards —
     * «جامعة» extracts as «ةعماج». The tell is positional: ة and ى are
     * word-FINAL letters in real Arabic but lead reversed words, and the
     * «ال» article prefix flips into a «لا» suffix. When reversed signals
     * outweigh normal ones across the text's Arabic words, the layer is
     * unusable.
     */
    private function looksLikeReversedArabic(string $text): bool
    {
        preg_match_all('/[\x{0620}-\x{064A}]{2,}/u', $text, $matches);
        $words = $matches[0];

        if (count($words) < 20) {
            return false;
        }

        $reversed = 0;
        $normal = 0;

        foreach ($words as $word) {
            $first = mb_substr($word, 0, 1);
            $last = mb_substr($word, -1);

            if ($first === 'ة' || $first === 'ى') {
                $reversed++;
            }

            if ($last === 'ة' || $last === 'ى') {
                $normal++;
            }

            if (mb_substr($word, 0, 2) === 'ال') {
                $normal++;
            }

            if (mb_substr($word, -2) === 'لا') {
                $reversed++;
            }
        }

        return $reversed > $normal;
    }
}
