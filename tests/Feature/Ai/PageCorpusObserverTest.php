<?php

use App\Jobs\Ai\IngestPageJob;
use App\Models\Page;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('ai.embeddings.driver', 'fake');

    Queue::fake();
});

function enableAiSearchForObserver(): void
{
    $settings = app(AiSettings::class);
    $settings->ai_enabled = true;
    $settings->search_enabled = true;
    $settings->save();
}

it('dispatches an ingest job on the ai queue when a page is created', function () {
    enableAiSearchForObserver();

    $page = Page::factory()->create();

    Queue::assertPushed(
        IngestPageJob::class,
        fn (IngestPageJob $job): bool => $job->pageId === $page->id && $job->queue === 'ai'
    );
});

it('dispatches an ingest job when a page is updated', function () {
    enableAiSearchForObserver();

    $page = Page::factory()->create();

    $page->update(['title' => 'عنوان جديد']);

    Queue::assertPushed(
        IngestPageJob::class,
        fn (IngestPageJob $job): bool => $job->pageId === $page->id
    );
});

it('dispatches an ingest job when a page is deleted so the job can evict it', function () {
    enableAiSearchForObserver();

    $page = Page::factory()->create();

    $page->delete();

    Queue::assertPushed(IngestPageJob::class, 2);
});

it('dispatches nothing while AI search is disabled', function () {
    Page::factory()->create();

    Queue::assertNothingPushed();
});

it('dispatches nothing when the embedding driver is unusable', function () {
    enableAiSearchForObserver();
    config()->set('ai.embeddings.driver', 'openrouter');
    config()->set('ai.providers.openrouter.key', '');

    Page::factory()->create();

    Queue::assertNothingPushed();
});
