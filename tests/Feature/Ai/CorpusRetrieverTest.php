<?php

use App\Ai\Corpus\CorpusRetriever;
use App\Ai\Corpus\CorpusSearchResult;
use App\Models\Corpus\CorpusChunk;
use App\Models\Corpus\CorpusItem;
use App\Models\Page;
use App\Settings\AiSettings;

beforeEach(function () {
    config()->set('ai.embeddings.driver', 'fake');
    config()->set('ai.embeddings.dimensions', 64);

    $settings = app(AiSettings::class);
    $settings->ai_enabled = true;
    $settings->search_enabled = true;
    $settings->save();
});

function seedArabicPage(string $title, string $body): Page
{
    return Page::factory()->create([
        'title' => $title,
        'html_content' => [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $body]]],
            ],
        ],
    ]);
}

it('returns the chunk of the relevant page for an arabic keyword query', function () {
    $plan = seedArabicPage('الخطة الدراسية', 'تحتوي الخطة على مقررات البرمجة وهياكل البيانات');
    seedArabicPage('القبول والتسجيل', 'شروط القبول تتطلب اجتياز اختبار القدرات العامة');

    $results = app(CorpusRetriever::class)->search('مقررات البرمجة');

    expect($results)->not->toBeEmpty()
        ->and($results->first())->toBeInstanceOf(CorpusSearchResult::class)
        ->and($results->first()->slug)->toBe($plan->slug)
        ->and($results->first()->title)->toBe('الخطة الدراسية')
        ->and($results->first()->content)->toContain('البرمجة')
        ->and($results->first()->score)->toBeGreaterThan(0.0);
});

it('matches across arabic orthography differences (tashkeel, alef, taa marbuta)', function () {
    $page = seedArabicPage('الجامعة', 'تقدم الجامعة مُقَرَّرات أساسية لكل الطلاب');

    $results = app(CorpusRetriever::class)->search('مقررات الجامعه الاساسيه');

    expect($results)->not->toBeEmpty()
        ->and($results->first()->slug)->toBe($page->slug);
});

it('returns an empty collection for an empty query', function () {
    seedArabicPage('صفحة', 'محتوى');

    expect(app(CorpusRetriever::class)->search('   '))->toBeEmpty();
});

it('returns an empty collection when nothing matches on the keyword-only sqlite path', function () {
    seedArabicPage('صفحة', 'محتوى عادي تماما');

    expect(app(CorpusRetriever::class)->search('xyzzy quixotic'))->toBeEmpty();
});

it('never surfaces chunks of items that are not ready', function () {
    $item = CorpusItem::factory()->pending()->create();
    CorpusChunk::factory()
        ->for($item, 'item')
        ->withContent('كلمة فريدة جدا للاختبار')
        ->create();

    expect(app(CorpusRetriever::class)->search('فريدة'))->toBeEmpty();
});

it('excludes chunks of a disabled item and surfaces them again once re-enabled', function () {
    $item = CorpusItem::factory()->disabled()->create();
    CorpusChunk::factory()
        ->for($item, 'item')
        ->withContent('كلمة فريدة جدا للاختبار المعطل')
        ->create();

    expect(app(CorpusRetriever::class)->search('فريدة'))->toBeEmpty();

    $item->update(['enabled' => true]);

    $results = app(CorpusRetriever::class)->search('فريدة');

    expect($results)->not->toBeEmpty()
        ->and($results->first()->content)->toContain('فريدة');
});

it('scopes nav-hidden pages out of public search but keeps them for the AI', function () {
    $public = seedArabicPage('صفحة عامة', 'محتوى عام يذكر كلمة زقفول الفريدة');
    $navHidden = Page::factory()->create([
        'title' => 'دليل المستجدين',
        'hidden' => true,
        'hidden_from_ai' => false,
        'html_content' => [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'محتوى المستجدين يذكر كلمة زقفول الفريدة']]],
            ],
        ],
    ]);

    // Public leg (the default the site search endpoint uses).
    $publicResults = app(CorpusRetriever::class)->search('زقفول');

    expect($publicResults->pluck('slug'))->toContain($public->slug)
        ->not->toContain($navHidden->slug);

    // AI leg opts in — the nav-hidden but AI-visible page is reachable.
    $aiResults = app(CorpusRetriever::class)->search('زقفول', includeHidden: true);

    expect($aiResults->pluck('slug'))->toContain($navHidden->slug);
});

it('respects the result limit', function () {
    foreach (range(1, 5) as $i) {
        seedArabicPage("صفحة رقم {$i}", "شرح البرمجة والتطوير في الصفحة رقم {$i}");
    }

    $results = app(CorpusRetriever::class)->search('البرمجة', limit: 2);

    expect($results)->toHaveCount(2);
});

it('ranks the chunk matching more query tokens first', function () {
    seedArabicPage('صفحة عامة', 'الحذف من المقررات متاح');
    $best = seedArabicPage('التقويم الأكاديمي', 'مواعيد الحذف والإضافة في التقويم الأكاديمي للمقررات');

    $results = app(CorpusRetriever::class)->search('مواعيد الحذف والاضافه');

    expect($results->first()->slug)->toBe($best->slug);
});
