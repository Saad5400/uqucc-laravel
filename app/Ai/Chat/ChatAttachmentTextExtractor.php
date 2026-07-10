<?php

namespace App\Ai\Chat;

use App\Ai\Corpus\DocumentExtractionAgent;
use App\Ai\Corpus\UploadedTextExtractor;
use App\Ai\Spend\SpendLedger;
use App\Models\Ai\ChatAttachment;
use App\Settings\AiSettings;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use RuntimeException;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Turns one chat attachment into markdown text — the chat-scoped variant of
 * {@see UploadedTextExtractor}: same routing (born-digital PDFs resolve on the
 * free text layer; scans and images go to the vision model), but it operates
 * on session-owned {@see ChatAttachment} rows and NEVER feeds the public
 * corpus — the result is context for that visitor's conversation only.
 *
 * Two chat-specific additions over the corpus extractor:
 *  - the vision path is budget-gated through the {@see SpendLedger} (a chat
 *    upload must not spend past the daily budget) and its exact cost is
 *    recorded on the ledger under the `assistant_attachment` feature;
 *  - the extracted markdown is capped, because it is replayed into the model
 *    as conversation context on every referencing turn.
 */
class ChatAttachmentTextExtractor
{
    /**
     * A PDF text layer shorter than this (in non-whitespace characters) is
     * treated as a scan artifact and routed to the vision model instead.
     */
    public const MIN_TEXT_LAYER_CHARS = 120;

    /**
     * Cap on the extracted markdown (characters): it becomes per-turn model
     * input for the rest of the conversation, so it must stay bounded.
     */
    public const MAX_EXTRACT_CHARS = 20_000;

    public function __construct(
        private readonly AiSettings $settings,
        private readonly SpendLedger $ledger,
    ) {}

    public function extract(ChatAttachment $attachment): string
    {
        $markdown = trim($this->extractRaw($attachment));

        if ($markdown === '') {
            throw new RuntimeException('لم يُستخرج أي نص من الملف — تحقق من أن الملف يحتوي نصاً مقروءاً.');
        }

        if (mb_strlen($markdown) > self::MAX_EXTRACT_CHARS) {
            $markdown = mb_substr($markdown, 0, self::MAX_EXTRACT_CHARS)."\n\n…[اقتُطع باقي النص]";
        }

        return $markdown;
    }

    private function extractRaw(ChatAttachment $attachment): string
    {
        if ($attachment->isPdf()) {
            $file = $attachment->localFile();
            $text = $this->fromPdfTextLayer($file->path);

            if ($this->isUsableTextLayer($text)) {
                return $text;
            }

            return $this->fromVisionModel($attachment);
        }

        if ($attachment->isImage()) {
            return $this->fromVisionModel($attachment);
        }

        throw new RuntimeException('نوع الملف غير مدعوم للاستخراج: '.($attachment->mime ?? 'غير معروف'));
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

    /**
     * The paid path: transcribe the file with the vision model, gated on the
     * AI kill switch, the OpenRouter key, and the daily budget; the exact
     * provider cost is recorded on the spend ledger.
     */
    private function fromVisionModel(ChatAttachment $attachment): string
    {
        if (! $this->settings->ai_enabled) {
            throw new RuntimeException('الذكاء الاصطناعي معطل من الإعدادات — لا يمكن استخراج النص عبر نموذج الرؤية.');
        }

        if ((string) config('ai.providers.openrouter.key', '') === '') {
            throw new RuntimeException('مفتاح OpenRouter غير مضبوط — لا يمكن استخراج النص عبر نموذج الرؤية.');
        }

        if (! $this->ledger->hasBudgetRemaining()) {
            throw new RuntimeException($this->ledger->budgetExhaustedMessage());
        }

        $this->ledger->clearContextCosts();

        $file = $attachment->localFile();

        $response = (new DocumentExtractionAgent)->prompt(
            'انسخ محتوى الملف المرفق كاملاً بصيغة ماركداون.'."\n\n"
                .'Attached file: '.$attachment->original_filename.' ('.$attachment->mime.')',
            [$this->attachmentFile($attachment, $file->path)],
            provider: (string) config('ai.default', 'openrouter'),
            model: $this->visionModel(),
            timeout: (int) config('ai.vision.timeout', 45),
        );

        $this->ledger->record(
            'assistant_attachment',
            $this->visionModel(),
            $response->usage,
            $this->ledger->captureContextCosts(),
        );

        return trim((string) $response->text);
    }

    private function attachmentFile(ChatAttachment $attachment, string $absolutePath): Document|Image
    {
        if ($attachment->isImage()) {
            return Image::fromPath($absolutePath, $attachment->mime)
                ->as($attachment->original_filename);
        }

        return Document::fromPath($absolutePath)
            ->as($attachment->original_filename);
    }

    /**
     * The operator-configured vision model, falling back to config.
     */
    private function visionModel(): string
    {
        $model = trim($this->settings->vision_model);

        return $model !== '' ? $model : (string) config('ai.vision.model', 'google/gemini-2.5-flash');
    }
}
