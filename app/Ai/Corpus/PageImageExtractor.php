<?php

namespace App\Ai\Corpus;

use App\Ai\Spend\SpendLedger;
use App\Models\Corpus\CorpusImageExtraction;
use App\Settings\AiSettings;
use App\Support\Disk;
use App\Support\LocalFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\Image;
use RuntimeException;
use Throwable;

/**
 * Resolves the text content of one page-embedded image for corpus ingestion,
 * backed by the permanent {@see CorpusImageExtraction} cache.
 *
 * Images WE store (/storage/... URLs, relative or absolute on our own host,
 * plus the media disk's own public URLs — the S3 object-storage form in
 * production) map to the media disk and are hashed by FILE bytes, so a
 * re-upload with new content re-OCRs while a plain page re-save hits the
 * cache; a remote media disk is streamed to a temp file instead of being
 * HTTP-fetched. External http(s) images are DOWNLOADED for OCR
 * (size/type-guarded, only at the moment a paid extraction is actually
 * possible) and cached permanently by URL hash — page content
 * overwhelmingly embeds immutable GitHub/imgur links, so one fetch per URL
 * is the right trade. Anything else (data: URIs, malformed sources) is
 * recorded as "skipped" and contributes only alt text.
 *
 * The vision call happens ONLY when $ocr is true (ingestion), the master
 * ai_enabled switch is on, the OpenRouter key is set, and the daily budget
 * has room; its exact cost lands on the spend ledger under `ingest`. Every
 * failure is contained — a broken image NEVER fails page ingestion, it just
 * yields no text (and a "failed" cache row that is retried next ingest).
 */
class PageImageExtractor
{
    /** The URL path prefix locally-served page images live under. */
    private const STORAGE_URL_PREFIX = '/storage/';

    /** Refuse to download external images larger than this (bytes). */
    private const MAX_DOWNLOAD_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly AiSettings $settings,
        private readonly SpendLedger $ledger,
    ) {}

    /**
     * The transcribed markdown for the image at $src, or null when none is
     * available (external image, vision off, no text, or failure).
     */
    public function extractedTextFor(string $src, bool $ocr = false): ?string
    {
        try {
            return $this->resolve(trim($src), $ocr);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function resolve(string $src, bool $ocr): ?string
    {
        if ($src === '') {
            return null;
        }

        $relativePath = $this->mediaDiskPathFor($src);

        if ($relativePath === null) {
            return $this->resolveExternal($src, $ocr);
        }

        $file = LocalFile::from(Disk::MEDIA, $relativePath);
        $hash = hash_file('sha256', $file->path);

        if ($hash === false) {
            return null;
        }

        $cached = CorpusImageExtraction::query()->where('content_hash', $hash)->first();

        if ($cached?->status === CorpusImageExtraction::STATUS_EXTRACTED) {
            $text = trim((string) $cached->extracted_text);

            return $text !== '' ? $text : null;
        }

        if (! $ocr || ! $this->visionIsAvailable()) {
            return null;
        }

        return $this->transcribe($file->path, $src, $hash);
    }

    /**
     * An external image, cached by URL hash. The download happens only when a
     * paid extraction can actually follow (ingestion + vision on + budget), so
     * a disabled installation never generates outbound traffic; a previously
     * "skipped" row (the pre-fetching behavior) upgrades on the next ingest.
     */
    private function resolveExternal(string $src, bool $ocr): ?string
    {
        if (! in_array(strtolower((string) parse_url($src, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            $this->rememberSkipped($src);

            return null;
        }

        $urlHash = hash('sha256', $src);
        $cached = CorpusImageExtraction::query()->where('content_hash', $urlHash)->first();

        if ($cached?->status === CorpusImageExtraction::STATUS_EXTRACTED) {
            $text = trim((string) $cached->extracted_text);

            return $text !== '' ? $text : null;
        }

        if (! $ocr || ! $this->visionIsAvailable() || ! $this->ledger->hasBudgetRemaining()) {
            return null;
        }

        $temporaryPath = $this->download($src, $urlHash);

        if ($temporaryPath === null) {
            return null;
        }

        try {
            return $this->transcribe($temporaryPath, $src, $urlHash);
        } finally {
            @unlink($temporaryPath);
        }
    }

    /**
     * Fetch an external image into a temp file, or null (with a "failed"
     * cache row, retried next ingest) when it is unreachable, not an image,
     * or over the size cap.
     */
    private function download(string $src, string $urlHash): ?string
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders(['Accept' => 'image/*'])
                ->maxRedirects(3)
                ->get($src);

            $contentType = strtolower(strtok((string) $response->header('Content-Type'), ';'));

            if (! $response->successful()
                || ! str_starts_with($contentType, 'image/')
                || strlen($response->body()) === 0
                || strlen($response->body()) > self::MAX_DOWNLOAD_BYTES) {
                throw new RuntimeException(sprintf(
                    'Unusable image response (status %d, type "%s", %d bytes).',
                    $response->status(),
                    $contentType,
                    strlen($response->body()),
                ));
            }

            $temporaryPath = tempnam(sys_get_temp_dir(), 'corpus-img-');

            if ($temporaryPath === false || file_put_contents($temporaryPath, $response->body()) === false) {
                return null;
            }

            return $temporaryPath;
        } catch (Throwable $exception) {
            report($exception);

            CorpusImageExtraction::query()->updateOrCreate(
                ['content_hash' => $urlHash],
                [
                    'source_url' => $src,
                    'extracted_text' => null,
                    'model' => null,
                    'status' => CorpusImageExtraction::STATUS_FAILED,
                ],
            );

            return null;
        }
    }

    /**
     * The media-disk path of an image WE store, or null for anything the
     * pipeline must not read from the disk. Two source forms qualify:
     * /storage/... paths (relative or in an absolute URL — our own host
     * serving the local driver), and URLs under the media disk's own public
     * base (the S3 object-storage form in production). Either way only when
     * the file actually exists on the media disk; everything else is
     * external and HTTP-fetched instead.
     */
    private function mediaDiskPathFor(string $src): ?string
    {
        $relative = null;
        $path = parse_url($src, PHP_URL_PATH);

        if (is_string($path) && str_starts_with($path, self::STORAGE_URL_PREFIX)) {
            $relative = substr($path, strlen(self::STORAGE_URL_PREFIX));
        } elseif (($base = $this->mediaUrlBase()) !== null && str_starts_with($src, $base)) {
            $relative = substr($src, strlen($base));
        }

        if ($relative === null || $relative === '' || str_contains($relative, '..')) {
            return null;
        }

        $disk = Storage::disk(Disk::MEDIA);

        foreach (array_unique([rawurldecode($relative), $relative]) as $candidate) {
            if ($disk->exists($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The media disk's public URL base ("…/" terminated), or null when the
     * disk cannot produce URLs.
     */
    private function mediaUrlBase(): ?string
    {
        try {
            $base = rtrim(Storage::disk(Disk::MEDIA)->url(''), '/');

            return $base === '' ? null : $base.'/';
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The paid path: transcribe the image file with the vision model and
     * cache the result permanently. A failure is cached as "failed" (retried
     * on a later ingest) and surfaces as "no text", never as an exception.
     */
    private function transcribe(string $absolutePath, string $src, string $hash): ?string
    {
        if (! $this->ledger->hasBudgetRemaining()) {
            return null;
        }

        try {
            $this->ledger->clearContextCosts();

            $response = (new DocumentExtractionAgent)->prompt(
                'انسخ المحتوى النصي للصورة المرفقة كاملاً بصيغة ماركداون. إن لم تحتوِ الصورة نصاً فصِف محتواها بإيجاز في سطر واحد.'."\n\n"
                    .'Attached image: '.basename($absolutePath),
                [Image::fromPath($absolutePath, $this->mimeFor($absolutePath))->as(basename($absolutePath))],
                provider: (string) config('ai.default', 'openrouter'),
                model: $this->visionModel(),
                timeout: (int) config('ai.vision.timeout', 45),
            );

            $this->ledger->record(
                'ingest',
                $this->visionModel(),
                $response->usage,
                $this->ledger->captureContextCosts(),
            );

            $text = trim((string) $response->text);

            CorpusImageExtraction::query()->updateOrCreate(
                ['content_hash' => $hash],
                [
                    'source_url' => $src,
                    'extracted_text' => $text,
                    'model' => $this->visionModel(),
                    'status' => CorpusImageExtraction::STATUS_EXTRACTED,
                ],
            );

            return $text !== '' ? $text : null;
        } catch (Throwable $exception) {
            report($exception);

            CorpusImageExtraction::query()->updateOrCreate(
                ['content_hash' => $hash],
                [
                    'source_url' => $src,
                    'extracted_text' => null,
                    'model' => null,
                    'status' => CorpusImageExtraction::STATUS_FAILED,
                ],
            );

            return null;
        }
    }

    /**
     * Record a non-fetchable image source (data: URI, malformed URL) as
     * permanently skipped, keyed by its URL hash (there are no file bytes to
     * hash). Idempotent, and it never downgrades a row that somehow holds an
     * extraction.
     */
    private function rememberSkipped(string $src): void
    {
        CorpusImageExtraction::query()->firstOrCreate(
            ['content_hash' => hash('sha256', $src)],
            [
                'source_url' => $src,
                'extracted_text' => null,
                'model' => null,
                'status' => CorpusImageExtraction::STATUS_SKIPPED,
            ],
        );
    }

    private function visionIsAvailable(): bool
    {
        return $this->settings->ai_enabled
            && (string) config('ai.providers.openrouter.key', '') !== '';
    }

    private function mimeFor(string $absolutePath): ?string
    {
        try {
            $mime = mime_content_type($absolutePath);

            return $mime === false ? null : $mime;
        } catch (Throwable) {
            return null;
        }
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
