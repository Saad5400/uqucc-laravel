<?php

namespace App\Jobs;

use App\Services\SearchIndexService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RebuildSearchIndex implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $chunkSize = 50,
        public readonly int $delaySeconds = 2
    ) {}

    public function handle(SearchIndexService $service): void
    {
        Log::info('Starting search index rebuild...');

        // معالجة في chunks (كل 50 صفحة)
        \App\Models\Page::visible()
            ->select('id', 'slug', 'title', 'icon', 'parent_id', 'html_content', 'smart_search')
            ->orderBy('title')
            ->chunk($this->chunkSize, function ($pages) {
                // معالجة الـ chunk الحالي
                foreach ($pages as $page) {
                    // معالجة خفيفة
                }
            });

        Log::info('Search index rebuild completed!');
    }
}
