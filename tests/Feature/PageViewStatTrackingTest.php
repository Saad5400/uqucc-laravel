<?php

use App\Models\Page;
use App\Models\PageViewStat;

it('truncates an over-long user agent to the column width instead of throwing', function () {
    $page = Page::factory()->create();

    // Real Instagram in-app browser UAs run well past varchar(255).
    $userAgent = 'Mozilla/5.0 (Linux; Android 12; '.str_repeat('CET-LX9 Build/HUAWEICET-L29; ', 20).') Instagram 432.1.0.44.80';

    expect(mb_strlen($userAgent))->toBeGreaterThan(255);

    PageViewStat::track(
        pageId: $page->id,
        userId: null,
        ipAddress: '178.73.100.89',
        userAgent: $userAgent,
    );

    $stat = PageViewStat::query()->where('page_id', $page->id)->sole();

    expect(mb_strlen((string) $stat->user_agent))->toBe(255)
        ->and($userAgent)->toStartWith((string) $stat->user_agent);
});

it('stores a normal-length user agent verbatim', function () {
    $page = Page::factory()->create();
    $userAgent = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36';

    PageViewStat::track($page->id, null, '10.0.0.1', $userAgent);

    expect(PageViewStat::query()->where('page_id', $page->id)->sole()->user_agent)
        ->toBe($userAgent);
});

it('keeps a null user agent null', function () {
    $page = Page::factory()->create();

    PageViewStat::track($page->id, null, '10.0.0.2', null);

    expect(PageViewStat::query()->where('page_id', $page->id)->sole()->user_agent)
        ->toBeNull();
});
