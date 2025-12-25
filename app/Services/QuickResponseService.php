<?php

namespace App\Services;

use App\Models\Page;
use Illuminate\Support\Collection;

class QuickResponseService
{
    /**
     * Get cached quick-response-ready payloads for all visible pages.
     */
    public function getCachedResponses(): Collection
    {
        return cache()->remember(
            config('app-cache.keys.quick_responses'),
            config('app-cache.quick_responses.ttl'),
            fn () => Page::visible()
                ->select([
                    'id',
                    'slug',
                    'title',
                    'html_content',
                    'updated_at',
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
     * Search cached payloads with a loose substring match.
     */
    public function search(string $query): ?Page
    {
        $needle = mb_strtolower($query);

        return $this->getCachedResponses()->first(function (Page $page) use ($needle) {
            $buttons = collect($page->quick_response_buttons ?? []);

            // Ensure html_content is a string (it can be an array from JSON decoding)
            $htmlContent = $page->html_content;
            $htmlContentString = is_array($htmlContent) ? '' : (string) $htmlContent;

            return str_contains(mb_strtolower($page->title), $needle);
        });
    }
}
