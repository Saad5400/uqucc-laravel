<?php

namespace App\Services\Telegram\Traits;

use App\Helpers\ArabicNormalizer;
use App\Models\Page;
use App\Services\QuickResponseService;

trait SearchesPages
{
    /**
     * Search for a page using the query.
     * Returns the first matching page or null if not found.
     */
    protected function searchPage(string $query): ?Page
    {
        $quickResponses = app(QuickResponseService::class);

        return $quickResponses->search($query);
    }

    /**
     * Check if the message matches a page title that doesn't require prefix.
     * Uses Arabic normalization to handle همزة and ال variations.
     */
    protected function findPageByDirectTitleMatch(string $content): ?Page
    {
        $normalizedQuery = ArabicNormalizer::normalize($content);
        $normalizedQueryWithoutAl = ArabicNormalizer::normalizeWithoutDefiniteArticle($content);

        $quickResponses = app(QuickResponseService::class);

        // Search for pages that don't require prefix and match the title
        return $quickResponses->getCachedResponses()->first(function (Page $page) use ($normalizedQuery, $normalizedQueryWithoutAl) {
            // Skip pages that require prefix
            if ($page->requires_prefix ?? true) {
                return false;
            }

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

            return false;
        });
    }

    /**
     * Find a page using smart search - checks if content contains the page title.
     * Uses Arabic normalization to handle همزة and ال variations.
     */
    protected function findPageBySmartSearch(string $content): ?Page
    {
        $normalizedContent = ArabicNormalizer::normalize($content);

        $quickResponses = app(QuickResponseService::class);

        // Search through all cached responses for smart search pages
        return $quickResponses->getCachedResponses()->first(function (Page $page) use ($normalizedContent) {
            if (! $page->smart_search) {
                return false;
            }

            $normalizedTitle = ArabicNormalizer::normalize($page->title);

            // Check if the message contains the page title (using normalized comparison)
            return str_contains($normalizedContent, $normalizedTitle);
        });
    }
}
