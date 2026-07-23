<?php

use App\Ai\Corpus\IngestPage;
use App\Models\Corpus\CorpusChunk;
use App\Models\Corpus\CorpusItem;
use App\Models\Page;
use App\Settings\AiSettings;

beforeEach(function () {
    config()->set('ai.embeddings.driver', 'fake');
    config()->set('ai.embeddings.dimensions', 64);
});

function enableAiSearch(): void
{
    $settings = app(AiSettings::class);
    $settings->ai_enabled = true;
    $settings->search_enabled = true;
    $settings->save();
}

function makeArabicPage(string $title, string $body, array $overrides = []): Page
{
    return Page::factory()->create([
        'title' => $title,
        'html_content' => [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'heading',
                    'attrs' => ['level' => 2],
                    'content' => [['type' => 'text', 'text' => 'تفاصيل']],
                ],
                [
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => $body]],
                ],
            ],
        ],
        ...$overrides,
    ]);
}

describe('page ingestion', function () {
    it('ingests a visible page into a ready corpus item with embedded, normalized chunks', function () {
        enableAiSearch();

        $page = makeArabicPage('الخطة الدراسية', 'يقدم القسم مُقَرَّرات البرمجة والرياضيات');

        $item = CorpusItem::query()->forPage($page)->first();

        expect($item)->not->toBeNull()
            ->and($item->status)->toBe(CorpusItem::STATUS_READY)
            ->and($item->title)->toBe('الخطة الدراسية')
            ->and($item->slug)->toBe($page->slug)
            ->and($item->checksum)->not->toBeNull();

        $chunks = $item->chunks()->orderBy('chunk_index')->get();

        expect($chunks)->not->toBeEmpty()
            ->and($chunks->first()->embedding)->not->toBeNull()
            // Tashkeel is folded away in the searchable copy but kept in content.
            ->and($chunks->pluck('normalized_content')->join(' '))->toContain('مقررات')
            ->and($chunks->pluck('content')->join(' '))->toContain('مُقَرَّرات');
    });

    it('is idempotent: re-ingesting unchanged content leaves the chunk rows untouched', function () {
        enableAiSearch();

        $page = makeArabicPage('القبول', 'شروط القبول في الجامعة');

        $item = CorpusItem::query()->forPage($page)->firstOrFail();
        $originalChunkIds = $item->chunks()->pluck('id')->all();
        $originalChecksum = $item->checksum;

        app(IngestPage::class)->ingest($page->fresh());

        $item->refresh();

        expect($item->checksum)->toBe($originalChecksum)
            ->and($item->chunks()->pluck('id')->all())->toBe($originalChunkIds);
    });

    it('re-chunks when the page content changes', function () {
        enableAiSearch();

        $page = makeArabicPage('التسجيل', 'محتوى قديم عن التسجيل');

        $item = CorpusItem::query()->forPage($page)->firstOrFail();
        $oldChecksum = $item->checksum;

        $page->update([
            'html_content' => [
                'type' => 'doc',
                'content' => [
                    ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'محتوى جديد كليا عن الحذف والإضافة']]],
                ],
            ],
        ]);

        $item->refresh();

        expect($item->checksum)->not->toBe($oldChecksum)
            ->and($item->chunks()->pluck('normalized_content')->join(' '))->toContain('جديد')
            ->and($item->chunks()->pluck('normalized_content')->join(' '))->not->toContain('قديم');
    });

    it('stamps source_updated_at from the page and refreshes it when only the date changes', function () {
        enableAiSearch();

        $page = makeArabicPage('المكافآت', 'تفاصيل مكافآت التفوق والامتياز');

        $item = CorpusItem::query()->forPage($page)->firstOrFail();

        expect($item->source_updated_at?->getTimestamp())->toBe($page->fresh()->updated_at->getTimestamp());

        $originalChecksum = $item->checksum;
        $originalChunkIds = $item->chunks()->pluck('id')->all();

        $this->travel(3)->days();

        // A save without a content change: the checksum guard must skip the
        // chunks but still refresh the freshness stamp.
        $page->fresh()->touch();

        $item->refresh();

        expect($item->checksum)->toBe($originalChecksum)
            ->and($item->chunks()->pluck('id')->all())->toBe($originalChunkIds)
            ->and($item->source_updated_at->getTimestamp())->toBe($page->fresh()->updated_at->getTimestamp());
    });

    it('does nothing while AI search is disabled', function () {
        $page = makeArabicPage('صفحة', 'محتوى لن يفهرس');

        app(IngestPage::class)->ingest($page);

        expect(CorpusItem::query()->count())->toBe(0)
            ->and(CorpusChunk::query()->count())->toBe(0);
    });

    it('skips safely when the real embedding driver has no API key', function () {
        enableAiSearch();
        config()->set('ai.embeddings.driver', 'openrouter');
        config()->set('ai.providers.openrouter.key', '');

        $page = makeArabicPage('صفحة', 'محتوى بدون مفتاح');

        app(IngestPage::class)->ingest($page);

        expect(CorpusItem::query()->count())->toBe(0);
    });

    it('keeps a page in the corpus when it is hidden from the site nav only', function () {
        enableAiSearch();

        $page = makeArabicPage('صفحة مخفية عن الموقع', 'محتوى ظاهر للذكاء');

        expect(CorpusItem::query()->forPage($page)->exists())->toBeTrue();

        $page->update(['hidden' => true]);

        expect(CorpusItem::query()->forPage($page)->exists())->toBeTrue()
            ->and(CorpusChunk::query()->count())->toBeGreaterThan(0);
    });

    it('never ingests a page that is hidden from the AI assistant', function () {
        enableAiSearch();

        $page = makeArabicPage('صفحة مخفية عن الذكاء', 'محتوى لا يفهرس', ['hidden_from_ai' => true]);

        expect(CorpusItem::query()->forPage($page)->exists())->toBeFalse();
    });

    it('evicts a page from the corpus when it becomes hidden from the AI assistant', function () {
        enableAiSearch();

        $page = makeArabicPage('صفحة تُخفى عن الذكاء لاحقا', 'محتوى ظاهر');

        expect(CorpusItem::query()->forPage($page)->exists())->toBeTrue();

        $page->update(['hidden_from_ai' => true]);

        expect(CorpusItem::query()->forPage($page)->exists())->toBeFalse()
            ->and(CorpusChunk::query()->count())->toBe(0);
    });

    it('evicts a page from the corpus when it is deleted', function () {
        enableAiSearch();

        $page = makeArabicPage('صفحة محذوفة', 'محتوى سيحذف');

        expect(CorpusItem::query()->forPage($page)->exists())->toBeTrue();

        $page->delete();

        expect(CorpusItem::query()->forPage($page)->exists())->toBeFalse();
    });
});

describe('ai:ingest-pages command', function () {
    it('ingests every AI-visible page (including nav-hidden) and prunes stale corpus items', function () {
        enableAiSearch();

        $visible = makeArabicPage('صفحة ظاهرة', 'محتوى ظاهر');
        $navHidden = makeArabicPage('صفحة مخفية عن الموقع', 'محتوى مخفي عن الموقع', ['hidden' => true]);

        $stale = CorpusItem::factory()->create(['source_id' => 999999]);

        $this->artisan('ai:ingest-pages')->assertSuccessful();

        expect(CorpusItem::query()->forPage($visible)->exists())->toBeTrue()
            ->and(CorpusItem::query()->forPage($navHidden)->exists())->toBeTrue()
            ->and(CorpusItem::query()->whereKey($stale->id)->exists())->toBeFalse()
            ->and(CorpusItem::query()->count())->toBe(2);
    });

    it('excludes and prunes pages hidden from the AI assistant', function () {
        enableAiSearch();

        $visible = makeArabicPage('صفحة ظاهرة للذكاء', 'محتوى ظاهر');
        $aiHidden = makeArabicPage('صفحة مخفية عن الذكاء', 'محتوى مخفي');

        // Ingested while still visible, then hidden from the AI directly in the
        // database so the observer does not evict it before the command runs.
        expect(CorpusItem::query()->forPage($aiHidden)->exists())->toBeTrue();
        Page::query()->whereKey($aiHidden->id)->update(['hidden_from_ai' => true]);

        $this->artisan('ai:ingest-pages')->assertSuccessful();

        expect(CorpusItem::query()->forPage($visible)->exists())->toBeTrue()
            ->and(CorpusItem::query()->forPage($aiHidden)->exists())->toBeFalse()
            ->and(CorpusItem::query()->count())->toBe(1);
    });

    it('fails with a warning when ingestion is disabled', function () {
        $this->artisan('ai:ingest-pages')->assertFailed();
    });
});
