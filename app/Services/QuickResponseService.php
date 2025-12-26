<?php

namespace App\Services;

use App\Models\Page;
use Illuminate\Support\Collection;

class QuickResponseService
{
    /**
     * Get cached quick-response-ready payloads for all pages visible in bot.
     */
    public function getCachedResponses(): Collection
    {
        return cache()->remember(
            config('app-cache.keys.quick_responses'),
            config('app-cache.quick_responses.ttl'),
            fn () => Page::visibleInBot()
                ->select([
                    'id',
                    'slug',
                    'title',
                    'html_content',
                    'updated_at',
                    'smart_search',
                    'requires_prefix',
                    'quick_response_auto_extract',
                    'quick_response_customize_message',
                    'quick_response_customize_buttons',
                    'quick_response_customize_attachments',
                    'quick_response_send_link',
                    'quick_response_message',
                    'quick_response_buttons',
                    'quick_response_attachments',
                ])
                ->orderBy('order')
                ->get()
        );
    }

    /**
     * Search cached payloads with exact match or smart search (substring match).
     */
    public function search(string $query): ?Page
    {
        $needle = mb_strtolower($query);

        return $this->getCachedResponses()->first(function (Page $page) use ($needle) {
            $title = mb_strtolower($page->title);

            // Exact match always works
            if ($title === $needle) {
                return true;
            }

            // Smart search: substring match if enabled
            if ($page->smart_search && str_contains($title, $needle)) {
                return true;
            }

            return false;
        });
    }
}
