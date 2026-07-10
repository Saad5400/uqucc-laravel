<?php

use App\Models\User;
use App\Support\Disk;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake(Disk::MEDIA);

    $this->editor = User::factory()->create();
    $this->editor->assignRole('editor');
});

it('redirects guests to the login page', function () {
    $this->post('/manage/pages/uploads', [
        'type' => 'editor',
        'file' => UploadedFile::fake()->image('photo.png'),
    ])->assertRedirect(route('manage.login'));
});

it('stores editor images at the public disk root like Filament\'s RichEditor', function () {
    $response = $this->actingAs($this->editor)->post('/manage/pages/uploads', [
        'type' => 'editor',
        'file' => UploadedFile::fake()->image('photo.png'),
    ]);

    $response->assertOk();
    $response->assertJsonStructure(['url', 'path']);

    $path = $response->json('path');
    expect($path)->not->toContain('/');
    expect($path)->toEndWith('.png');
    Storage::disk(Disk::MEDIA)->assertExists($path);
    expect($response->json('url'))->toBe(Storage::disk(Disk::MEDIA)->url($path));
});

it('accepts the type as a query parameter, as the rich editor sends it', function () {
    $response = $this->actingAs($this->editor)->post('/manage/pages/uploads?type=editor', [
        'file' => UploadedFile::fake()->image('pasted.png'),
    ]);

    $response->assertOk();
    $response->assertJsonStructure(['url', 'path']);
    Storage::disk(Disk::MEDIA)->assertExists($response->json('path'));
});

it('stores quick response attachments in quick-responses with the original filename', function () {
    $response = $this->actingAs($this->editor)->post('/manage/pages/uploads', [
        'type' => 'quick_response',
        'file' => UploadedFile::fake()->create('report.pdf', 100, 'application/pdf'),
    ]);

    $response->assertOk();
    expect($response->json('path'))->toBe('quick-responses/report.pdf');
    Storage::disk(Disk::MEDIA)->assertExists('quick-responses/report.pdf');
});

it('deduplicates quick response filenames with a numeric suffix instead of overwriting', function () {
    $upload = fn () => $this->actingAs($this->editor)->post('/manage/pages/uploads', [
        'type' => 'quick_response',
        'file' => UploadedFile::fake()->create('report.pdf', 100, 'application/pdf'),
    ]);

    $upload();
    $second = $upload();
    $third = $upload();

    expect($second->json('path'))->toBe('quick-responses/report-1.pdf');
    expect($third->json('path'))->toBe('quick-responses/report-2.pdf');
});

it('rejects non-image files for the editor type', function () {
    $this->actingAs($this->editor)->post('/manage/pages/uploads', [
        'type' => 'editor',
        'file' => UploadedFile::fake()->create('report.pdf', 100, 'application/pdf'),
    ])->assertSessionHasErrors('file');
});

it('rejects files over the size limit and unknown types', function () {
    $this->actingAs($this->editor)->post('/manage/pages/uploads', [
        'type' => 'quick_response',
        'file' => UploadedFile::fake()->create('big.pdf', 13000, 'application/pdf'),
    ])->assertSessionHasErrors('file');

    $this->actingAs($this->editor)->post('/manage/pages/uploads', [
        'type' => 'unknown',
        'file' => UploadedFile::fake()->image('photo.png'),
    ])->assertSessionHasErrors('type');
});
