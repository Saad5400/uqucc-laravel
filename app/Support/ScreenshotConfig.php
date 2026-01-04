<?php

namespace App\Support;

class ScreenshotConfig
{
    public static function format(): string
    {
        return strtolower(config('screenshots.format', 'jpeg'));
    }

    public static function extension(): string
    {
        $extension = config('screenshots.extension');

        if ($extension) {
            return ltrim(strtolower($extension), '.');
        }

        $format = self::format();

        return $format === 'jpeg' ? 'jpg' : $format;
    }

    public static function mimeType(): string
    {
        $format = self::format();

        return config("screenshots.mime_types.{$format}") ?? "image/{$format}";
    }

    public static function quality(): ?int
    {
        $quality = config('screenshots.quality');

        return is_numeric($quality) ? (int) $quality : null;
    }

    public static function directory(): string
    {
        return rtrim(config('screenshots.directory', storage_path('app/public/screenshots')), '/');
    }

    public static function cacheControl(): string
    {
        return config('screenshots.cache_control', 'public, max-age=604800');
    }
}
