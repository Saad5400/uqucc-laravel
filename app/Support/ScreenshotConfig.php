<?php

namespace App\Support;

/**
 * The file conventions the site's share cards are written under — one place so
 * the extension on disk, the mime type on the meta tag and the directory the
 * cleanup command sweeps can never disagree.
 *
 * The "screenshot" in the name is historical: these images were browser
 * screenshots of the page before {@see \App\Services\OgImageService} started
 * drawing them. The storage directory, the cache key and `storage:cleanup
 * --screenshots` all still use the word, so the class keeps it rather than
 * leaving the vocabulary half-renamed.
 */
class ScreenshotConfig
{
    public static function extension(): string
    {
        return config('screenshots.extension');
    }

    public static function mimeType(): string
    {
        return config('screenshots.mime_type');
    }

    public static function directory(): string
    {
        return rtrim(config('screenshots.directory'), '/');
    }

    public static function cacheControl(): string
    {
        return config('screenshots.cache_control');
    }
}
