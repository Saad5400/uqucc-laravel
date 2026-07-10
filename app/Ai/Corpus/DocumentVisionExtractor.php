<?php

namespace App\Ai\Corpus;

use App\Models\Corpus\CorpusDocument;
use App\Settings\AiSettings;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use RuntimeException;

/**
 * Sends an uploaded corpus file to the vision model and returns its markdown
 * transcription — the fallback for images and for PDFs with no usable text
 * layer (scans).
 *
 * PDFs are attached DIRECTLY as documents: laravel/ai 0.9's OpenRouter
 * gateway maps Document attachments to Chat Completions "file" content parts
 * (base64 data URLs), which OpenRouter fans out to the model's native PDF
 * input — no page-to-image conversion needed. Images go as image_url parts.
 *
 * The model comes from the operator-editable AiSettings->vision_model,
 * falling back to config('ai.vision.model'). Guarded by the master AI kill
 * switch and the OpenRouter key so a queued extraction never silently calls
 * out against operator intent — it throws, and the job records the message
 * on the document row.
 */
class DocumentVisionExtractor
{
    public function __construct(private readonly AiSettings $settings) {}

    public function extract(CorpusDocument $document): string
    {
        if (! $this->settings->ai_enabled) {
            throw new RuntimeException('الذكاء الاصطناعي معطل من الإعدادات — لا يمكن استخراج النص عبر نموذج الرؤية.');
        }

        if ((string) config('ai.providers.openrouter.key', '') === '') {
            throw new RuntimeException('مفتاح OpenRouter غير مضبوط — لا يمكن استخراج النص عبر نموذج الرؤية.');
        }

        $response = (new DocumentExtractionAgent)->prompt(
            $this->prompt($document),
            [$this->attachment($document)],
            provider: (string) config('ai.default', 'openrouter'),
            model: $this->model(),
            timeout: (int) config('ai.vision.timeout', 45),
        );

        return trim((string) $response->text);
    }

    private function prompt(CorpusDocument $document): string
    {
        return 'انسخ محتوى الملف المرفق كاملاً بصيغة ماركداون.'."\n\n"
            .'Attached file: '.$document->original_filename.' ('.$document->mime.')';
    }

    private function attachment(CorpusDocument $document): Document|Image
    {
        if ($document->isImage()) {
            return Image::fromPath($document->absolutePath(), $document->mime)
                ->as($document->original_filename);
        }

        return Document::fromPath($document->absolutePath())
            ->as($document->original_filename);
    }

    /**
     * The operator-configured vision model, falling back to config.
     */
    private function model(): string
    {
        $model = trim($this->settings->vision_model);

        return $model !== '' ? $model : (string) config('ai.vision.model', 'google/gemini-2.5-flash');
    }
}
