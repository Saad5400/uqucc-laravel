<?php

use App\Support\LocalFile;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\DecoratedAdapter;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * Register a disk whose adapter is NOT a LocalFilesystemAdapter (it decorates
 * one), so LocalFile takes the remote materialization path while the bytes
 * still live on the local test filesystem.
 */
function fakeRemoteDisk(string $name = 'remote-test'): FilesystemAdapter
{
    $root = storage_path('framework/testing/'.$name);

    @mkdir($root, 0755, true);

    $adapter = new class(new LocalFilesystemAdapter($root)) extends DecoratedAdapter {};
    $disk = new FilesystemAdapter(new Flysystem($adapter), $adapter, ['root' => $root]);

    Storage::set($name, $disk);

    return $disk;
}

it('returns the real stored path for local (and faked) disks without copying', function () {
    Storage::fake('media');
    Storage::disk('media')->put('images/photo.png', 'png-bytes');

    $file = LocalFile::from('media', 'images/photo.png');

    expect($file->path)->toBe(Storage::disk('media')->path('images/photo.png'))
        ->and(file_get_contents($file->path))->toBe('png-bytes');

    $path = $file->path;
    unset($file);

    expect(file_exists($path))->toBeTrue();
});

it('materializes a remote disk file to a temp path and deletes it on destruct', function () {
    $disk = fakeRemoteDisk();
    $disk->put('docs/guide.pdf', 'pdf-bytes');

    $file = LocalFile::from('remote-test', 'docs/guide.pdf');

    expect($file->path)->not->toBe($disk->path('docs/guide.pdf'))
        ->and($file->path)->toEndWith('.pdf')
        ->and(file_get_contents($file->path))->toBe('pdf-bytes');

    $path = $file->path;
    unset($file);

    expect(file_exists($path))->toBeFalse();
});

it('throws when the remote file cannot be read', function () {
    fakeRemoteDisk();

    expect(fn () => LocalFile::from('remote-test', 'missing.pdf'))
        ->toThrow(RuntimeException::class);
});

it('creates temporary download targets that clean themselves up', function () {
    $file = LocalFile::temporary('jpg');

    expect(file_exists($file->path))->toBeTrue()
        ->and($file->path)->toEndWith('.jpg');

    file_put_contents($file->path, 'downloaded');
    $path = $file->path;
    unset($file);

    expect(file_exists($path))->toBeFalse();
});
