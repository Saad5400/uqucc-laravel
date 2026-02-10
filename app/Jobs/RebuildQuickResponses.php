<?php

namespace App\Jobs;

use App\Models\Page;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class RebuildQuickResponses implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Use chunking to process pages in batches
        $responses = collect();
        $chunkSize = 50;

        Page::visibleInBot()
            ->select([
                'id',
                'slug',
                'title',
                'hidden',
                'smart_search',
                'requires_prefix',
                'quick_response_auto_extract_message',
                'quick_response_auto_extract_buttons',
                'quick_response_auto_extract_attachments',
                'quick_response_send_link',
                'quick_response_send_screenshot',
                'quick_response_message',
                'quick_response_buttons',
                'quick_response_attachments',
            ])
            ->orderBy('order')
            ->chunk($chunkSize, function (EloquentCollection $pages) use (&$responses) {
                $responses = $responses->concat($pages);
            });

        // Cache the responses for 24 hours
        Cache::put(
            config('app-cache.keys.quick_responses'),
            $responses,
            now()->addHours(24)
        );
    }
}
