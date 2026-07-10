<?php

use App\Models\Ai\PageContentProposal;
use App\Models\Page;
use App\Support\Disk;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    Storage::fake(Disk::MEDIA, ['url' => 'https://fsn1.your-objectstorage.com/uqucc']);
});

function mediaUrl(string $path): string
{
    return 'https://fsn1.your-objectstorage.com/uqucc/'.$path;
}

/**
 * A page holding every stored shape of a legacy /storage URL: an inline
 * image node nested inside a paragraph (the real stored shape), an <img>
 * inside customBlock attrs.config HTML, and a link mark href.
 */
function makeLegacyPage(): Page
{
    Storage::disk('public')->put('editor-image.png', 'png-bytes');
    Storage::disk('public')->put('quick-responses/دليل الطالب.pdf', 'pdf-bytes');

    return Page::factory()->create([
        'html_content' => [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [
                    ['type' => 'text', 'text' => 'انظر الصورة في /storage/editor-image.png أدناه'],
                    ['type' => 'image', 'attrs' => ['src' => '/storage/editor-image.png', 'alt' => 'مخطط', 'title' => null]],
                ]],
                [
                    'type' => 'customBlock',
                    'attrs' => [
                        'id' => 'alert',
                        'config' => [
                            'content' => '<p>تنبيه</p><img src="https://uqucc.com/storage/editor-image.png" alt="مخطط">',
                        ],
                    ],
                ],
                ['type' => 'paragraph', 'content' => [[
                    'type' => 'text',
                    'text' => 'دليل الطالب',
                    'marks' => [['type' => 'link', 'attrs' => ['href' => '/storage/quick-responses/دليل الطالب.pdf']]],
                ]]],
            ],
        ],
        'quick_response_attachments' => [
            'quick-responses/دليل الطالب.pdf',
            'https://uqucc.com/storage/editor-image.png',
            'https://example.com/external.pdf',
        ],
        'quick_response_buttons' => [
            ['text' => 'الدليل', 'url' => '/storage/quick-responses/دليل الطالب.pdf'],
            ['text' => 'خارجي', 'url' => 'https://example.com/page'],
        ],
        'quick_response_message' => 'حمل الدليل من https://uqucc.com/storage/editor-image.png الآن',
    ]);
}

it('copies public-disk files to the media disk and rewrites every stored /storage reference', function () {
    $page = makeLegacyPage();

    $this->artisan('storage:migrate-to-s3')->assertSuccessful();

    Storage::disk(Disk::MEDIA)->assertExists('editor-image.png');
    Storage::disk(Disk::MEDIA)->assertExists('quick-responses/دليل الطالب.pdf');

    $content = $page->fresh()->html_content;

    // Inline image node nested in a paragraph.
    expect($content['content'][0]['content'][1]['attrs']['src'])->toBe(mediaUrl('editor-image.png'))
        // Plain prose is never rewritten.
        ->and($content['content'][0]['content'][0]['text'])->toContain('/storage/editor-image.png')
        // <img> inside customBlock attrs.config HTML strings.
        ->and($content['content'][1]['attrs']['config']['content'])->toContain('src="'.mediaUrl('editor-image.png').'"')
        // Link mark hrefs.
        ->and($content['content'][2]['content'][0]['marks'][0]['attrs']['href'])->toBe(mediaUrl('quick-responses/دليل الطالب.pdf'));

    $page = $page->fresh();

    // Attachment entries: relative stays relative, own absolute URL is
    // normalized to a relative path, external URLs are untouched.
    expect($page->quick_response_attachments)->toBe([
        'quick-responses/دليل الطالب.pdf',
        'editor-image.png',
        'https://example.com/external.pdf',
    ]);

    expect($page->quick_response_buttons[0]['url'])->toBe(mediaUrl('quick-responses/دليل الطالب.pdf'))
        ->and($page->quick_response_buttons[1]['url'])->toBe('https://example.com/page')
        ->and($page->quick_response_message)->toBe('حمل الدليل من '.mediaUrl('editor-image.png').' الآن');
});

it('is idempotent: a second run copies nothing and rewrites nothing', function () {
    makeLegacyPage();

    $this->artisan('storage:migrate-to-s3')->assertSuccessful();

    $snapshot = Page::query()->first()->only(['html_content', 'quick_response_attachments', 'quick_response_buttons', 'quick_response_message', 'updated_at']);

    $this->artisan('storage:migrate-to-s3')
        ->expectsOutputToContain('Files: 0 copied, 2 already up to date, 0 failed.')
        ->expectsOutputToContain('Pages: 0 rewritten.')
        ->assertSuccessful();

    expect(Page::query()->first()->only(['html_content', 'quick_response_attachments', 'quick_response_buttons', 'quick_response_message', 'updated_at']))
        ->toEqual($snapshot);
});

it('reports without changing anything under --dry-run', function () {
    $page = makeLegacyPage();
    $original = $page->fresh()->html_content;

    $this->artisan('storage:migrate-to-s3 --dry-run')
        ->expectsOutputToContain('Would copy: editor-image.png')
        ->expectsOutputToContain('Pages: 1 would be rewritten.')
        ->assertSuccessful();

    Storage::disk(Disk::MEDIA)->assertMissing('editor-image.png');
    expect($page->fresh()->html_content)->toEqual($original);
});

it('rewrites legacy HTML-string pages too', function () {
    Storage::disk('public')->put('old.png', 'bytes');

    $page = Page::factory()->create([
        'html_content' => '<p>قديم</p><img src="/storage/old.png">',
    ]);

    $this->artisan('storage:migrate-to-s3')->assertSuccessful();

    expect($page->fresh()->html_content)->toBe('<p>قديم</p><img src="'.mediaUrl('old.png').'">');
});

it('leaves external URLs and references to missing files untouched', function () {
    $page = Page::factory()->create([
        'html_content' => [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [
                    ['type' => 'image', 'attrs' => ['src' => 'https://i.imgur.com/external.png', 'alt' => null]],
                    ['type' => 'image', 'attrs' => ['src' => 'https://github.com/repo/storage/thing.png', 'alt' => null]],
                    ['type' => 'image', 'attrs' => ['src' => '/storage/never-existed.png', 'alt' => null]],
                ]],
            ],
        ],
    ]);

    $original = $page->fresh()->html_content;

    $this->artisan('storage:migrate-to-s3')
        ->expectsOutputToContain('Unresolved reference')
        ->assertSuccessful();

    expect($page->fresh()->html_content)->toEqual($original);
});

it('skips regenerable cache directories when copying', function () {
    Storage::disk('public')->put('screenshots/og_home.png', 'cache');
    Storage::disk('public')->put('external-attachments/abc_file.pdf', 'cache');
    Storage::disk('public')->put('kept.png', 'real');

    $this->artisan('storage:migrate-to-s3')->assertSuccessful();

    Storage::disk(Disk::MEDIA)->assertExists('kept.png');
    Storage::disk(Disk::MEDIA)->assertMissing('screenshots/og_home.png');
    Storage::disk(Disk::MEDIA)->assertMissing('external-attachments/abc_file.pdf');
});

it('rewrites pending AI content proposals', function () {
    Storage::disk('public')->put('proposal-image.png', 'bytes');

    $proposal = PageContentProposal::factory()->create([
        'proposed_html_content' => [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [[
                    'type' => 'image', 'attrs' => ['src' => '/storage/proposal-image.png', 'alt' => null],
                ]]],
            ],
        ],
    ]);

    $this->artisan('storage:migrate-to-s3')->assertSuccessful();

    expect($proposal->fresh()->proposed_html_content['content'][0]['content'][0]['attrs']['src'])
        ->toBe(mediaUrl('proposal-image.png'));
});

it('rewrites trashed pages so restoring them keeps working images', function () {
    Storage::disk('public')->put('trashed.png', 'bytes');

    $page = Page::factory()->create([
        'html_content' => [
            'type' => 'doc',
            'content' => [['type' => 'paragraph', 'content' => [[
                'type' => 'image', 'attrs' => ['src' => '/storage/trashed.png', 'alt' => null],
            ]]]],
        ],
    ]);
    $page->delete();

    $this->artisan('storage:migrate-to-s3')->assertSuccessful();

    expect(Page::withTrashed()->find($page->id)->html_content['content'][0]['content'][0]['attrs']['src'])
        ->toBe(mediaUrl('trashed.png'));
});
