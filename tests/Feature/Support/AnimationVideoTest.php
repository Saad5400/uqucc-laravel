<?php

use App\Support\AnimationVideo;
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

    $first = app(AnimationVideo::class)->fromGif($gif);

    expect($first)->not->toBeNull()
        ->and($first)->toEndWith('.mp4')
        ->and(substr((string) file_get_contents($first), 4, 4))->toBe('ftyp');

    $modifiedAt = filemtime($first);
    config(['services.ffmpeg.binary' => '/nonexistent/ffmpeg']);

    expect(app(AnimationVideo::class)->fromGif($gif))->toBe($first)
        ->and(filemtime($first))->toBe($modifiedAt);
})->skip(fn (): bool => (new ExecutableFinder)->find('ffmpeg') === null, 'ffmpeg is not installed');

it('answers null and leaves nothing behind when ffmpeg is missing', function () {
    config(['services.ffmpeg.binary' => '/nonexistent/ffmpeg']);

    expect(app(AnimationVideo::class)->fromGif(animatedGif($this->directory)))->toBeNull()
        ->and(glob($this->directory.'/storage/app/cache/gif-videos/*'))->toBe([]);
});

it('answers null for a GIF that does not exist', function () {
    expect(@app(AnimationVideo::class)->fromGif($this->directory.'/missing.gif'))->toBeNull();
});

it('tells a silent video from one with sound', function () {
    $silent = $this->directory.'/silent.mp4';
    $withSound = $this->directory.'/sound.mp4';
    exec('ffmpeg -v error -f lavfi -i color=black:s=16x16:d=1 -c:v libx264 '.escapeshellarg($silent));
    exec('ffmpeg -v error -f lavfi -i color=black:s=16x16:d=1 -f lavfi -i sine=d=1 -shortest -c:v libx264 -c:a aac '.escapeshellarg($withSound));

    expect(app(AnimationVideo::class)->isSilent($silent))->toBeTrue()
        ->and(app(AnimationVideo::class)->isSilent($withSound))->toBeFalse();
})->skip(fn (): bool => (new ExecutableFinder)->find('ffmpeg') === null, 'ffmpeg is not installed');

it('counts a video ffprobe cannot read as having sound, so nothing is dropped', function () {
    config(['services.ffmpeg.ffprobe_binary' => '/nonexistent/ffprobe']);

    expect(app(AnimationVideo::class)->isSilent(animatedGif($this->directory)))->toBeFalse();
});
