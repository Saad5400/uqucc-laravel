<?php

namespace App\Ai\Corpus;

/**
 * How {@see PageContentExtractor} renders a page's images when it flattens
 * TipTap content to markdown. The two families exist for two different
 * consumers and must not be confused:
 *
 * - the TRANSCRIPTION cases feed the AI corpus, where an image only matters
 *   as searchable text, so each one becomes a `[محتوى صورة: ...]` block
 *   carrying the vision model's reading of it;
 * - {@see self::MarkdownImage} feeds the EDIT round-trip (read a page as
 *   markdown → the model rewrites it → write it back), where the image is
 *   the content. Transcriptions are one-way: writing one back replaces the
 *   screenshot with a wall of OCR text and loses the image for good.
 */
enum ImageRendering
{
    /** `[محتوى صورة: ...]` + the cached transcription; never pays for OCR. */
    case CachedTranscription;

    /** Same, but may OCR still-uncached images (paid). Ingestion only. */
    case FreshTranscription;

    /** `![alt](src)` — the only case that survives a write-back. */
    case MarkdownImage;

    /** Whether this case may trigger a new (paid) vision extraction. */
    public function mayOcr(): bool
    {
        return $this === self::FreshTranscription;
    }
}
