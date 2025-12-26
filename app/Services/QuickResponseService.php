<?php

namespace App\Services;

use App\Helpers\ArabicNormalizer;
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
     * Uses Arabic normalization to handle همزة and ال variations.
     */
    public function search(string $query): ?Page
    {
        $normalizedQuery = ArabicNormalizer::normalize($query);
        $normalizedQueryWithoutAl = ArabicNormalizer::normalizeWithoutDefiniteArticle($query);

        return $this->getCachedResponses()->first(function (Page $page) use ($normalizedQuery, $normalizedQueryWithoutAl) {
            $normalizedTitle = ArabicNormalizer::normalize($page->title);
            $normalizedTitleWithoutAl = ArabicNormalizer::normalizeWithoutDefiniteArticle($page->title);

            // Exact match with normalization
            if ($normalizedTitle === $normalizedQuery) {
                return true;
            }

            // Match allowing ال variations (e.g., "هياكل" matches "الهياكل")
            if ($normalizedTitleWithoutAl === $normalizedQueryWithoutAl) {
                return true;
            }

            // Smart search: substring match if enabled (using normalized text)
            if ($page->smart_search && str_contains($normalizedTitle, $normalizedQuery)) {
                return true;
            }

            return false;
        });
    }
}
