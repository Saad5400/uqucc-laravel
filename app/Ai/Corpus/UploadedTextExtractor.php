<?php

namespace App\Ai\Corpus;

use App\Models\Corpus\CorpusDocument;
use RuntimeException;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Turns an uploaded corpus file into markdown text.
 *
 * Routing:
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
     * A PDF text layer shorter than this (in non-whitespace characters) is
     * treated as a scan artifact — page numbers or stray glyphs — and the
     * document is routed to the vision model instead.
     */
    public const MIN_TEXT_LAYER_CHARS = 120;

    public function __construct(private readonly DocumentVisionExtractor $vision) {}

    public function extract(CorpusDocument $document): string
    {
        $markdown = trim($this->extractRaw($document));

        if ($markdown === '') {
            throw new RuntimeException('لم يُستخرج أي نص من الملف — تحقق من أن الملف يحتوي نصاً مقروءاً.');
        }

        return $markdown;
    }

    private function extractRaw(CorpusDocument $document): string
    {
        if ($document->isPdf()) {
            $text = $this->fromPdfTextLayer($document->absolutePath());

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

    private function isUsableTextLayer(string $text): bool
    {
        $compact = (string) preg_replace('/\s+/u', '', $text);

        return mb_strlen($compact) >= self::MIN_TEXT_LAYER_CHARS;
    }
}
