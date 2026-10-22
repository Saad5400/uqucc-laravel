<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * A GIF re-encoded as a sharp H.264 MP4, for sending to Telegram as an
 * animation.
 *
 * Telegram turns every GIF a bot uploads into an MP4 of its own, at a bitrate
 * low enough to smear text into an unreadable blur — a flyer with a moving
 * background comes out worst, the motion eating what bitrate there is. An MP4
 * it is handed already encoded is kept as it is, so the conversion is done
 * here, at a quality that keeps small Arabic text legible.
 *
 * Each GIF is converted once and the video reused on every send after, keyed
 * by the GIF's contents so an edited file gets a fresh one. Any failure —
 * ffmpeg missing, a corrupt GIF — is logged and answered with null, and the
 * caller sends the GIF itself: a blurry animation beats none.
 */
class GifVideo
{
    /**
     * Seconds to wait on one conversion: enough for a long, large GIF, and a
     * ceiling so a wedged ffmpeg does not hold a queue worker.
     */
    private const TIMEOUT = 120;

    /**
     * The path of the GIF's MP4, converting it on first use, or null when it
     * could not be converted.
     */
    public function mp4For(string $gifPath): ?string
    {
        $hash = md5_file($gifPath);

        if ($hash === false) {
            return null;
        }

        $directory = storage_path('app/cache/gif-videos');
        $videoPath = "{$directory}/{$hash}.mp4";

        if (is_file($videoPath)) {
            return $videoPath;
        }

        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            return null;
        }

        // Written beside the final path and renamed into place, so a send
        // running alongside never picks up a half-written video.
        $partialPath = "{$directory}/{$hash}.".bin2hex(random_bytes(4)).'.partial.mp4';

        $process = new Process([
            $this->ffmpegBinary(),
            '-y', '-v', 'error',
            '-i', $gifPath,
            '-an',
            '-c:v', 'libx264',
            '-preset', 'slow',
            '-crf', '16',
            '-pix_fmt', 'yuv420p',
            // H.264 wants even dimensions; a GIF may have odd ones.
            '-vf', 'scale=trunc(iw/2)*2:trunc(ih/2)*2',
            '-movflags', '+faststart',
            $partialPath,
        ], timeout: self::TIMEOUT);

        try {
            $process->run();
        } catch (\Throwable $e) {
            return $this->fail($partialPath, $gifPath, $e->getMessage());
        }

        if (! $process->isSuccessful() || ! is_file($partialPath) || filesize($partialPath) === 0) {
            return $this->fail($partialPath, $gifPath, trim($process->getErrorOutput()));
        }

        rename($partialPath, $videoPath);

        return $videoPath;
    }

    private function fail(string $partialPath, string $gifPath, string $reason): null
    {
        @unlink($partialPath);

        Log::warning('Could not convert a GIF to MP4, sending the GIF itself', [
            'gif' => $gifPath,
            'reason' => $reason,
        ]);

        return null;
    }

    /**
     * The ffmpeg to run — `ffmpeg` on PATH unless the deployment pinned one.
     */
    private function ffmpegBinary(): string
    {
        $binary = config('services.ffmpeg.binary');

        return is_string($binary) && $binary !== '' ? $binary : 'ffmpeg';
    }
}
