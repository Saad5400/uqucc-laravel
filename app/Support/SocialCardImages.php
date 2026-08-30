<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Turns an image `src` from page content into something a card can draw.
 *
 * The engine has no network and no loader: an image reaches it as a data: URI
 * with an explicit pixel box, or it does not reach it at all. So each source is
 * resolved to bytes here — off the media disk for the images we store, over
 * HTTP for the external ones page content links to, straight through for a
 * data: URI — and handed back with the size it should be drawn at.
 *
 * Everything about this is capped, because page content is not a trusted size:
 * a per-image byte ceiling, a total across one card, and a count. A source that
 * blows a cap, fails to load, or is not an image is simply not returned — the
 * caller draws the alt text instead. Nothing here throws; a card missing one
 * picture is a smaller loss than a card that failed to render.
 */
class SocialCardImages
{
    /** Refuse a single image heavier than this (bytes, before base64). */
    private const MAX_IMAGE_BYTES = 2 * 1024 * 1024;

    /** Refuse to carry more than this across one card (bytes, before base64). */
    private const MAX_TOTAL_BYTES = 4 * 1024 * 1024;

    /**
     * Downscale anything wider than this before embedding, when GD is
     * available. A page screenshot is routinely 3000px wide and drawn at 600;
     * carrying the other 2400 columns through a JSON pipe costs seconds.
     */
    private const DOWNSCALE_WIDTH = 1600;

    /** How tall one image may be drawn, in CSS pixels — a tall portrait must not own the whole card. */
    private const MAX_DISPLAY_HEIGHT = 380;

    /** The URL path prefix locally-served page images live under. */
    private const STORAGE_URL_PREFIX = '/storage/';

    /** Bytes already spent on the card being built. */
    private int $spent = 0;

    /** @var array<string, array{uri: string, width: int, height: int}|null> */
    private array $memo = [];

    /**
     * Start a new card's image budget. The instance is reused across renders
     * (it is resolved from the container), so the total has to be reset rather
     * than accumulated.
     */
    public function reset(): void
    {
        $this->spent = 0;
    }

    /**
     * The image at `$src`, ready to embed, or null when it cannot be drawn.
     *
     * @param  int  $maxWidth  The width available to it, in CSS pixels.
     * @return array{uri: string, width: int, height: int}|null
     */
    public function resolve(string $src, int $maxWidth): ?array
    {
        $src = trim($src);

        if ($src === '') {
            return null;
        }

        return $this->memo[$src.'@'.$maxWidth] ??= $this->load($src, $maxWidth);
    }

    /**
     * @return array{uri: string, width: int, height: int}|null
     */
    private function load(string $src, int $maxWidth): ?array
    {
        try {
            $bytes = $this->bytesFor($src);
        } catch (Throwable $exception) {
            Log::warning('Card image could not be read', ['src' => $src, 'error' => $exception->getMessage()]);

            return null;
        }

        if ($bytes === null || $this->spent + strlen($bytes) > self::MAX_TOTAL_BYTES) {
            return null;
        }

        $size = @getimagesizefromstring($bytes);

        // No intrinsic size means no box to draw it in: the engine needs one,
        // and guessing it would stretch the picture.
        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            return null;
        }

        [$intrinsicWidth, $intrinsicHeight, $type] = $size;

        [$bytes, $intrinsicWidth, $intrinsicHeight] = $this->downscaled($bytes, $intrinsicWidth, $intrinsicHeight);

        $this->spent += strlen($bytes);

        $scale = min(1.0, $maxWidth / $intrinsicWidth, self::MAX_DISPLAY_HEIGHT / $intrinsicHeight);

        return [
            'uri' => 'data:'.(image_type_to_mime_type($type) ?: 'image/png').';base64,'.base64_encode($bytes),
            'width' => max(1, (int) round($intrinsicWidth * $scale)),
            'height' => max(1, (int) round($intrinsicHeight * $scale)),
        ];
    }

    /**
     * The image's bytes, or null when the source is one we do not fetch.
     */
    private function bytesFor(string $src): ?string
    {
        if (str_starts_with($src, 'data:')) {
            return $this->fromDataUri($src);
        }

        $mediaPath = $this->mediaDiskPathFor($src);

        if ($mediaPath !== null) {
            $bytes = (string) Storage::disk(Disk::MEDIA)->get($mediaPath);

            return strlen($bytes) <= self::MAX_IMAGE_BYTES ? $bytes : null;
        }

        return $this->fromUrl($src);
    }

    private function fromDataUri(string $src): ?string
    {
        if (! str_starts_with($src, 'data:image/') || ! str_contains($src, ';base64,')) {
            return null;
        }

        $bytes = base64_decode(substr($src, strpos($src, ';base64,') + 8), strict: true);

        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_IMAGE_BYTES) {
            return null;
        }

        return $bytes;
    }

    /**
     * An external image, fetched with a short leash.
     *
     * Only the bot's card can reach this: the link-preview card is drawn with
     * no image budget precisely so a crawler's request never waits on somebody
     * else's server.
     */
    private function fromUrl(string $src): ?string
    {
        if (! in_array(strtolower((string) parse_url($src, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return null;
        }

        $response = Http::timeout(4)->connectTimeout(2)->withOptions(['stream' => false])->get($src);

        if (! $response->successful() || ! str_starts_with((string) $response->header('Content-Type'), 'image/')) {
            return null;
        }

        $bytes = $response->body();

        return $bytes !== '' && strlen($bytes) <= self::MAX_IMAGE_BYTES ? $bytes : null;
    }

    /**
     * The same picture at a sane width, when GD is here to do it.
     *
     * Guarded rather than assumed: the extension is present everywhere this
     * runs today, and an image carried at its original size still draws
     * correctly, so its absence costs payload rather than correctness.
     *
     * @return array{0: string, 1: int, 2: int}
     */
    private function downscaled(string $bytes, int $width, int $height): array
    {
        if ($width <= self::DOWNSCALE_WIDTH || ! function_exists('imagecreatefromstring')) {
            return [$bytes, $width, $height];
        }

        $source = @imagecreatefromstring($bytes);

        if ($source === false) {
            return [$bytes, $width, $height];
        }

        $targetWidth = self::DOWNSCALE_WIDTH;
        $targetHeight = max(1, (int) round($height * ($targetWidth / $width)));
        $target = imagecreatetruecolor($targetWidth, $targetHeight);

        // Transparency survives the resample: a logo on a transparent ground
        // would otherwise land on a black rectangle in the middle of the card.
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagepng($target, null, 6);
        $resampled = (string) ob_get_clean();

        imagedestroy($source);
        imagedestroy($target);

        return $resampled === '' ? [$bytes, $width, $height] : [$resampled, $targetWidth, $targetHeight];
    }

    /**
     * The media-disk path a src points at, or null when it points elsewhere.
     *
     * Mirrors {@see \App\Ai\Corpus\PageImageExtractor}: both the local
     * `/storage/...` form and the disk's own public URL base (the S3 form in
     * production) resolve to the same object.
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

    private function mediaUrlBase(): ?string
    {
        try {
            $base = Storage::disk(Disk::MEDIA)->url('');
        } catch (Throwable) {
            return null;
        }

        return $base === '' ? null : rtrim($base, '/').'/';
    }
}
