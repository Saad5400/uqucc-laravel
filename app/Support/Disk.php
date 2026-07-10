<?php

namespace App\Support;

/**
 * The application's logical storage disks — the single source of truth for
 * disk names, so every upload/read site and every test refers to the same
 * disk (see config/filesystems.php, where each name resolves to a local
 * driver by default and to S3 in production via env).
 */
final class Disk
{
    /**
     * Public, user-visible media: rich-editor page images and Telegram
     * quick-response attachments. Files here are served by stable public
     * URLs (locally under /storage via the storage:link symlink; on S3 as
     * public-read objects).
     */
    public const MEDIA = 'media';

    /**
     * Private working files: corpus documents and chat attachments. Never
     * publicly addressable — they are only read server-side (text
     * extraction, vision OCR).
     */
    public const UPLOADS = 'uploads';
}
