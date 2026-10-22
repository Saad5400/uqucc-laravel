<?php

use App\Support\GifVideo;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;

/** A two-frame GIF at odd dimensions, which H.264 cannot take as they are. */
function animatedGif(string $directory): string
{
    $path = "{$directory}/flyer.gif";
    $frame = imagecreatetruecolor(15, 11);
    ob_start();
    imagegif($frame);
    file_put_contents($path, (string) ob_get_clean());
    imagedestroy($frame);

    return $path;
}

beforeEach(function () {
    $this->directory = sys_get_temp_dir().'/gif-video-test-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->directory);
    $this->app->useStoragePath($this->directory.'/storage');
});

afterEach(function () {
    File::deleteDirectory($this->directory);
});

it('converts a GIF to an MP4 once and reuses it after', function () {
    $gif = animatedGif($this->directory);

    $first = app(GifVideo::class)->mp4For($gif);

    expect($first)->not->toBeNull()
        ->and($first)->toEndWith('.mp4')
        ->and(substr((string) file_get_contents($first), 4, 4))->toBe('ftyp');

    $modifiedAt = filemtime($first);
    config(['services.ffmpeg.binary' => '/nonexistent/ffmpeg']);

    expect(app(GifVideo::class)->mp4For($gif))->toBe($first)
        ->and(filemtime($first))->toBe($modifiedAt);
})->skip(fn (): bool => (new ExecutableFinder)->find('ffmpeg') === null, 'ffmpeg is not installed');

it('answers null and leaves nothing behind when ffmpeg is missing', function () {
    config(['services.ffmpeg.binary' => '/nonexistent/ffmpeg']);

    expect(app(GifVideo::class)->mp4For(animatedGif($this->directory)))->toBeNull()
        ->and(glob($this->directory.'/storage/app/cache/gif-videos/*'))->toBe([]);
});

it('answers null for a GIF that does not exist', function () {
    expect(@app(GifVideo::class)->mp4For($this->directory.'/missing.gif'))->toBeNull();
});
