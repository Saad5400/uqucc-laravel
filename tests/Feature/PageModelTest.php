<?php

use App\Models\Page;
use Database\Factories\PageFactory;
use Illuminate\Support\Facades\Cache;

describe('htmlContent accessor and mutator', function () {
    it('stores a TipTap array as JSON and returns it as an array', function () {
        $document = [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'مرحبا']]],
            ],
        ];

        $page = PageFactory::new()->create(['html_content' => $document]);

        expect($page->getRawOriginal('html_content'))->toBe(json_encode($document));
        expect($page->fresh()->html_content)->toBe($document);
    });

    it('stores a JSON string untouched and decodes it back to an array on read', function () {
        $json = '{"type":"doc","content":[{"type":"paragraph"}]}';

        $page = PageFactory::new()->create(['html_content' => $json]);

        expect($page->getRawOriginal('html_content'))->toBe($json);
        expect($page->fresh()->html_content)->toBe([
            'type' => 'doc',
            'content' => [['type' => 'paragraph']],
        ]);
    });

    it('returns legacy non-JSON HTML strings as-is', function () {
        $html = '<p>محتوى قديم</p>';

        $page = PageFactory::new()->create(['html_content' => $html]);

        expect($page->fresh()->html_content)->toBe($html);
    });

    it('returns a valid JSON string that is not an array as the original string', function () {
        $page = PageFactory::new()->create(['html_content' => '"just a quoted string"']);

        expect($page->fresh()->html_content)->toBe('"just a quoted string"');
    });

    it('returns blank content unchanged', function () {
        $page = PageFactory::new()->create(['html_content' => '']);

        expect($page->fresh()->html_content)->toBe('');
    });
});

describe('visibility scopes', function () {
    it('filters hidden pages via the visible scope', function () {
        $visible = PageFactory::new()->create();
        PageFactory::new()->hidden()->create();

        expect(Page::visible()->pluck('id')->all())->toBe([$visible->id]);
    });

    it('filters bot-hidden pages via the visibleInBot scope', function () {
        $visible = PageFactory::new()->create();
        PageFactory::new()->hiddenFromBot()->create();

        expect(Page::visibleInBot()->pluck('id')->all())->toBe([$visible->id]);
    });

    it('hides a page from the bot but not the website independently', function () {
        $page = PageFactory::new()->hiddenFromBot()->create();

        expect(Page::visible()->pluck('id')->all())->toContain($page->id);
        expect(Page::visibleInBot()->pluck('id')->all())->not->toContain($page->id);
    });

    it('filters AI-hidden pages via the visibleToAi scope', function () {
        $visible = PageFactory::new()->create();
        PageFactory::new()->hiddenFromAi()->create();

        expect(Page::visibleToAi()->pluck('id')->all())->toBe([$visible->id]);
    });

    it('hides a page from the AI assistant but not the website or bot independently', function () {
        $page = PageFactory::new()->hiddenFromAi()->create();

        expect(Page::visible()->pluck('id')->all())->toContain($page->id);
        expect(Page::visibleInBot()->pluck('id')->all())->toContain($page->id);
        expect(Page::visibleToAi()->pluck('id')->all())->not->toContain($page->id);
    });

    it('returns only pages without a parent via the rootLevel scope', function () {
        $root = PageFactory::new()->create();
        PageFactory::new()->childOf($root)->create();

        expect(Page::rootLevel()->pluck('id')->all())->toBe([$root->id]);
    });

    it('returns only smart-search pages via the smartSearch scope', function () {
        PageFactory::new()->create();
        $smart = PageFactory::new()->create(['smart_search' => true]);

        expect(Page::smartSearch()->pluck('id')->all())->toBe([$smart->id]);
    });
});

describe('cache invalidation hooks', function () {
    function primeAppCaches(): void
    {
        Cache::put(config('app-cache.keys.navigation_tree'), ['stale']);
        Cache::put(config('app-cache.keys.search_data'), ['stale']);
        Cache::put(config('app-cache.keys.quick_responses'), ['stale']);
    }

    function expectAppCachesFlushed(): void
    {
        expect(Cache::has(config('app-cache.keys.navigation_tree')))->toBeFalse();
        expect(Cache::has(config('app-cache.keys.search_data')))->toBeFalse();
        expect(Cache::has(config('app-cache.keys.quick_responses')))->toBeFalse();
    }

    it('flushes navigation, search and quick-response caches on save', function () {
        $page = PageFactory::new()->create();

        primeAppCaches();
        $page->update(['title' => 'عنوان جديد']);

        expectAppCachesFlushed();
    });

    it('flushes caches on delete', function () {
        $page = PageFactory::new()->create();

        primeAppCaches();
        $page->delete();

        expectAppCachesFlushed();
    });

    it('flushes caches on restore', function () {
        $page = PageFactory::new()->create();
        $page->delete();

        primeAppCaches();
        $page->restore();

        expectAppCachesFlushed();
    });
});

describe('sortable behaviour', function () {
    it('assigns order per parent when creating', function () {
        $root = PageFactory::new()->create();
        $siblingA = PageFactory::new()->childOf($root)->create();
        $siblingB = PageFactory::new()->childOf($root)->create();
        $otherRoot = PageFactory::new()->create();

        expect($siblingA->order)->toBe(1);
        expect($siblingB->order)->toBe(2);
        expect($otherRoot->order)->toBe(2);
    });
});
