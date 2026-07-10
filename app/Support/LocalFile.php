<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;

/**
 * A file that is guaranteed to exist at a real local filesystem path — the
 * bridge between env-resolved storage disks (which may be S3 in production)
 * and consumers that need an on-disk path (vision `Image::fromPath`, the PDF
 * parser, Telegram file downloads).
 *
 * For disks backed by a local adapter (including every `Storage::fake`) this
 * is the stored file itself; for remote disks the object is streamed to a
 * temp file that is deleted when the instance is garbage-collected — so hold
 * the instance in scope for as long as `$path` is being read. Never call
 * `Storage::disk(...)->path()` on a potentially-remote disk; use this.
 */
final class LocalFile
{
    private function __construct(
        public readonly string $path,
        private readonly bool $temporary,
    ) {}

    /**
     * Materialize the file at $path on $disk to a local filesystem path.
     *
     * @throws RuntimeException when the file cannot be read from the disk.
     */
    public static function from(string $disk, string $path): self
    {
        $storage = Storage::disk($disk);

        if ($storage->getAdapter() instanceof LocalFilesystemAdapter) {
            return new self($storage->path($path), temporary: false);
        }

        $stream = $storage->readStream($path);

        if (! is_resource($stream)) {
            throw new RuntimeException("Unable to read \"{$path}\" from disk \"{$disk}\".");
        }

        $local = self::temporary(pathinfo($path, PATHINFO_EXTENSION));
        $target = fopen($local->path, 'wb');

        if ($target === false || stream_copy_to_stream($stream, $target) === false) {
            throw new RuntimeException("Unable to copy \"{$path}\" from disk \"{$disk}\" to a temporary file.");
        }

        fclose($target);
        fclose($stream);

        return $local;
    }

    /**
     * An empty temporary file (deleted on garbage collection) — a safe local
     * download target for bytes that are then pushed onto a storage disk.
     */
    public static function temporary(string $extension = ''): self
    {
        $path = tempnam(sys_get_temp_dir(), 'uqucc-file-');

        if ($path === false) {
            throw new RuntimeException('Unable to create a temporary file.');
        }

        if ($extension !== '') {
            $withExtension = $path.'.'.$extension;

            if (! rename($path, $withExtension)) {
                throw new RuntimeException('Unable to create a temporary file.');
            }

            $path = $withExtension;
        }

        return new self($path, temporary: true);
    }

    public function __destruct()
    {
        if ($this->temporary) {
            @unlink($this->path);
        }
    }
}
