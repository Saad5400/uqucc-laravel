<?php

use App\Models\Page;
use App\Services\SearchIndexService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('builds search index without infinite loops for cyclic parent pages', function () {
    $pageA = Page::create([
        'slug' => 'page-a',
        'title' => 'Page A',
        'html_content' => '<p>Content A</p>',
        'parent_id' => null,
    ]);

    $pageB = Page::create([
        'slug' => 'page-b',
        'title' => 'Page B',
        'html_content' => '<p>Content B</p>',
        'parent_id' => $pageA->id,
    ]);

    $pageA->update(['parent_id' => $pageB->id]);

    $index = app(SearchIndexService::class)->buildIndex();

    expect($index)->toHaveCount(2);

    $entryA = collect($index)->firstWhere('slug', 'page-a');
    $entryB = collect($index)->firstWhere('slug', 'page-b');

    expect($entryA['breadcrumb'])->toBe('Page B / Page A');
    expect($entryB['breadcrumb'])->toBe('Page A / Page B');
});
