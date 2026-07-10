<?php

use App\Ai\Corpus\DocumentExtractionAgent;
use App\Ai\Corpus\PageContentExtractor;
use App\Models\Ai\AiUsage;
use App\Models\Corpus\CorpusImageExtraction;
use App\Models\Corpus\CorpusItem;
use App\Models\Page;
use App\Settings\AiSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');

    config()->set('ai.embeddings.driver', 'fake');
    config()->set('ai.embeddings.dimensions', 64);

    $settings = app(AiSettings::class);
    $settings->ai_enabled = true;
    $settings->search_enabled = true;
    $settings->daily_budget_usd = 5.0;
    $settings->save();
});

/**
 * Store a fake image on the public disk and return its /storage/... URL.
 */
function storePublicImage(string $path = 'pages/chart.png'): string
{
    Storage::disk('public')->put(
        $path,
        (string) UploadedFile::fake()->image(basename($path))->getContent(),
    );

    return '/storage/'.$path;
}

/**
 * A page whose TipTap content is: paragraph, inline image (the real stored
 * shape — image nodes live INSIDE paragraphs), paragraph.
 */
function makeImagePage(string $src, string $alt = '', array $overrides = []): Page
{
    return Page::factory()->create([
        'title' => 'الخطة الدراسية',
        'html_content' => [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'مقدمة عن الخطة الدراسية']]],
                ['type' => 'paragraph', 'content' => [[
                    'type' => 'image',
                    'attrs' => ['src' => $src, 'alt' => $alt, 'title' => null, 'width' => null, 'height' => null, 'id' => null],
                ]]],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'خاتمة الصفحة']]],
            ],
        ],
        ...$overrides,
    ]);
}

function ingestedText(Page $page): string
{
    return CorpusItem::query()->forPage($page)->firstOrFail()
        ->chunks()->orderBy('chunk_index')->pluck('content')->implode("\n");
}

describe('page image extraction', function () {
    it('OCRs a locally stored image during ingestion, caching the transcription and recording the spend', function () {
        config()->set('ai.providers.openrouter.key', 'test-key');
        DocumentExtractionAgent::fake(['جدول مقررات المستوى الأول: برمجة 101']);

        $src = storePublicImage();
        $page = makeImagePage($src, alt: 'جدول الخطة');

        $text = ingestedText($page);

        expect($text)->toContain('[محتوى صورة: جدول الخطة]')
            ->toContain('جدول مقررات المستوى الأول: برمجة 101');

        $extraction = CorpusImageExtraction::query()->sole();

        expect($extraction->status)->toBe(CorpusImageExtraction::STATUS_EXTRACTED)
            ->and($extraction->content_hash)->toBe(hash('sha256', Storage::disk('public')->get('pages/chart.png')))
            ->and($extraction->extracted_text)->toContain('برمجة 101');

        expect(AiUsage::query()->where('feature', 'ingest')->count())->toBe(1);
    });

    it('never re-OCRs an unchanged image on re-ingestion (permanent cache)', function () {
        config()->set('ai.providers.openrouter.key', 'test-key');

        $visionCalls = 0;
        DocumentExtractionAgent::fake(function () use (&$visionCalls): string {
            $visionCalls++;

            return 'نص مستخرج من الصورة';
        });

        $src = storePublicImage();
        $page = makeImagePage($src, alt: 'جدول');

        // A content edit busts the checksum, forcing a full re-ingest.
        $content = $page->html_content;
        $content['content'][0]['content'][0]['text'] = 'مقدمة معدلة عن الخطة';
        $page->update(['html_content' => $content]);

        expect($visionCalls)->toBe(1)
            ->and(CorpusImageExtraction::query()->count())->toBe(1)
            ->and(AiUsage::query()->where('feature', 'ingest')->count())->toBe(1)
            ->and(ingestedText($page->fresh()))->toContain('نص مستخرج من الصورة');
    });

    it('falls back to alt text without caching when the vision model is unavailable', function () {
        config()->set('ai.providers.openrouter.key', '');
        DocumentExtractionAgent::fake(['يجب ألا يُستدعى النموذج.']);

        $src = storePublicImage();
        $page = makeImagePage($src, alt: 'جدول الخطة الدراسية');

        expect(ingestedText($page))->toContain('[صورة: جدول الخطة الدراسية]')
            ->not->toContain('محتوى صورة');

        expect(CorpusImageExtraction::query()->count())->toBe(0);

        DocumentExtractionAgent::assertNeverPrompted();
    });

    it('keeps alt text and skips vision when the master ai switch is off', function () {
        config()->set('ai.providers.openrouter.key', 'test-key');
        DocumentExtractionAgent::fake(['يجب ألا يُستدعى النموذج.']);

        $settings = app(AiSettings::class);
        $settings->ai_enabled = false;
        $settings->save();

        $src = storePublicImage();
        $page = Page::factory()->make(['title' => 'الخطة']);
        $page->html_content = [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [[
                    'type' => 'image',
                    'attrs' => ['src' => $src, 'alt' => 'وصف بديل'],
                ]]],
            ],
        ];

        $markdown = app(PageContentExtractor::class)->extractForIngestion($page);

        expect($markdown)->toContain('[صورة: وصف بديل]');

        DocumentExtractionAgent::assertNeverPrompted();
    });

    it('marks external-host images skipped and keeps only their alt text', function () {
        config()->set('ai.providers.openrouter.key', 'test-key');
        DocumentExtractionAgent::fake(['يجب ألا يُستدعى النموذج.']);

        $src = 'https://i.imgur.com/abc123.png';
        $page = makeImagePage($src, alt: 'شعار الكلية');

        expect(ingestedText($page))->toContain('[صورة: شعار الكلية]');

        $extraction = CorpusImageExtraction::query()->sole();

        expect($extraction->status)->toBe(CorpusImageExtraction::STATUS_SKIPPED)
            ->and($extraction->content_hash)->toBe(hash('sha256', $src))
            ->and($extraction->source_url)->toBe($src);

        DocumentExtractionAgent::assertNeverPrompted();
    });

    it('marks a failed vision call failed and still ingests the page', function () {
        config()->set('ai.providers.openrouter.key', 'test-key');
        DocumentExtractionAgent::fake([fn () => throw new RuntimeException('vision exploded')]);

        $src = storePublicImage();
        $page = makeImagePage($src, alt: 'مخطط');

        $item = CorpusItem::query()->forPage($page)->firstOrFail();

        expect($item->status)->toBe(CorpusItem::STATUS_READY)
            ->and(ingestedText($page))->toContain('[صورة: مخطط]')
            ->and(CorpusImageExtraction::query()->sole()->status)->toBe(CorpusImageExtraction::STATUS_FAILED);
    });

    it('places the image block at the image position in the page markdown', function () {
        config()->set('ai.providers.openrouter.key', 'test-key');
        DocumentExtractionAgent::fake(['نص من داخل الصورة']);

        $src = storePublicImage();
        $page = makeImagePage($src, alt: 'مخطط');

        $text = ingestedText($page);

        $intro = mb_strpos($text, 'مقدمة عن الخطة الدراسية');
        $image = mb_strpos($text, '[محتوى صورة: مخطط]');
        $outro = mb_strpos($text, 'خاتمة الصفحة');

        expect($intro)->not->toBeFalse()
            ->and($image)->not->toBeFalse()
            ->and($outro)->not->toBeFalse()
            ->and($intro)->toBeLessThan($image)
            ->and($image)->toBeLessThan($outro);
    });

    it('extracts img tags embedded in customBlock config html', function () {
        config()->set('ai.providers.openrouter.key', 'test-key');
        DocumentExtractionAgent::fake(['يجب ألا يُستدعى النموذج.']);

        $page = Page::factory()->create([
            'title' => 'التحويل',
            'html_content' => [
                'type' => 'doc',
                'content' => [
                    [
                        'type' => 'customBlock',
                        'attrs' => [
                            'id' => 'alert',
                            'config' => [
                                'icon' => null,
                                'content' => '<p>راجع الجدول</p><img src="https://uqu.edu.sa/x.png" alt="جدول المفاضلة">',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        expect(ingestedText($page))->toContain('[صورة: جدول المفاضلة]');

        expect(CorpusImageExtraction::query()->sole()->status)->toBe(CorpusImageExtraction::STATUS_SKIPPED);

        DocumentExtractionAgent::assertNeverPrompted();
    });

    it('drops images with neither alt text nor extractable content silently', function () {
        config()->set('ai.providers.openrouter.key', '');

        $page = makeImagePage('https://github.com/user-attachments/assets/deadbeef', alt: '');

        expect(ingestedText($page))->not->toContain('صورة')
            ->toContain('مقدمة عن الخطة الدراسية');
    });
});
