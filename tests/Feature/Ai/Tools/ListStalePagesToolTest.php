<?php

use App\Ai\Tools\ListStalePagesTool;
use App\Models\Page;
use App\Settings\AiSettings;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    config()->set('ai.embeddings.driver', 'fake');
    config()->set('ai.embeddings.dimensions', 64);

    $settings = app(AiSettings::class);
    $settings->ai_enabled = true;
    $settings->search_enabled = true;
    $settings->save();
});

function makeAgedPage(string $title, string $slug, int $monthsOld, array $overrides = []): Page
{
    return Page::factory()->create([
        'title' => $title,
        'slug' => $slug,
        'updated_at' => now()->subMonths($monthsOld),
        ...$overrides,
    ]);
}

it('lists stale published pages oldest first with slug, date and days since update', function () {
    makeAgedPage('الصفحة الأقدم', '/qadeem/jiddan', 30);
    makeAgedPage('الصفحة الأحدث قدماً', '/qadeem', 15);
    makeAgedPage('صفحة حديثة', '/hadeeth', 1);

    $reply = (string) app(ListStalePagesTool::class)->handle(new Request([]));

    expect($reply)->toContain('الصفحة الأقدم')
        ->toContain('slug: /qadeem/jiddan')
        ->toContain('آخر تحديث: '.now()->subMonths(30)->toDateString())
        ->toContain('منذ')
        ->not->toContain('صفحة حديثة');

    expect(mb_strpos($reply, 'الصفحة الأقدم'))->toBeLessThan(mb_strpos($reply, 'الصفحة الأحدث قدماً'));
});

it('respects a custom months_threshold', function () {
    makeAgedPage('عمرها ستة أشهر', '/sitta', 6);

    $default = (string) app(ListStalePagesTool::class)->handle(new Request([]));
    $custom = (string) app(ListStalePagesTool::class)->handle(new Request(['months_threshold' => 3]));

    expect($default)->toContain('لا توجد صفحات')
        ->and($custom)->toContain('عمرها ستة أشهر');
});

it('never lists hidden pages', function () {
    makeAgedPage('صفحة مخفية', '/srri', 24, ['hidden' => true]);

    $reply = (string) app(ListStalePagesTool::class)->handle(new Request([]));

    expect($reply)->toContain('لا توجد صفحات')
        ->not->toContain('صفحة مخفية');
});

it('caps results at the requested limit and at 50 overall', function () {
    foreach (range(1, 4) as $i) {
        makeAgedPage("صفحة {$i}", "/qadeema/{$i}", 13 + $i);
    }

    $limited = (string) app(ListStalePagesTool::class)->handle(new Request(['limit' => 2]));
    $overflow = (string) app(ListStalePagesTool::class)->handle(new Request(['limit' => 999]));

    expect(preg_match_all('/^\d+\. /mu', $limited))->toBe(2)
        ->and(preg_match_all('/^\d+\. /mu', $overflow))->toBe(4);
});

it('is gated on the search feature toggle', function () {
    makeAgedPage('صفحة قديمة', '/qadeem', 20);

    $settings = app(AiSettings::class);
    $settings->search_enabled = false;
    $settings->save();

    $reply = (string) app(ListStalePagesTool::class)->handle(new Request([]));

    expect($reply)->toContain('غير متاح')
        ->not->toContain('صفحة قديمة');
});

it('is gated on the master ai kill switch', function () {
    makeAgedPage('صفحة قديمة', '/qadeem', 20);

    $settings = app(AiSettings::class);
    $settings->ai_enabled = false;
    $settings->save();

    $reply = (string) app(ListStalePagesTool::class)->handle(new Request([]));

    expect($reply)->toContain('غير متاح')
        ->not->toContain('صفحة قديمة');
});
