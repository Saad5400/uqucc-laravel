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

    /**
     * Aggressive search that tries to find a page no matter what.
     * First tries partial contains match, then falls back to similarity matching.
     */
    protected function aggressiveSearch(string $query): ?Page
    {
        $quickResponses = app(QuickResponseService::class);

        // First try: find any page title that contains the query
        $containsMatch = $this->findPageByContains($query);
        if ($containsMatch) {
            return $containsMatch;
        }

        // Second try: find the closest match using similarity
        return $this->findClosestMatch($query);
    }

    /**
     * Find a page where the title contains the query.
     * Uses Arabic normalization.
     */
    protected function findPageByContains(string $query): ?Page
    {
        $normalizedQuery = ArabicNormalizer::normalize($query);
        $normalizedQueryWithoutAl = ArabicNormalizer::normalizeWithoutDefiniteArticle($query);

        $quickResponses = app(QuickResponseService::class);

        return $quickResponses->getCachedResponses()->first(function (Page $page) use ($normalizedQuery, $normalizedQueryWithoutAl) {
            $normalizedTitle = ArabicNormalizer::normalize($page->title);
            $normalizedTitleWithoutAl = ArabicNormalizer::normalizeWithoutDefiniteArticle($page->title);

            // Check if title contains the query
            if (str_contains($normalizedTitle, $normalizedQuery)) {
                return true;
            }

            // Check without ال
            if (str_contains($normalizedTitleWithoutAl, $normalizedQueryWithoutAl)) {
                return true;
            }

            // Check if query contains the title (reverse check)
            if (str_contains($normalizedQuery, $normalizedTitle)) {
                return true;
            }

            return false;
        });
    }

    /**
     * Find the closest matching page using similarity scoring.
     * Returns the page with the highest similarity score above a threshold.
     */
    protected function findClosestMatch(string $query): ?Page
    {
        $normalizedQuery = ArabicNormalizer::normalize($query);
        $normalizedQueryWithoutAl = ArabicNormalizer::normalizeWithoutDefiniteArticle($query);

        $quickResponses = app(QuickResponseService::class);
        $pages = $quickResponses->getCachedResponses();

        $bestMatch = null;
        $bestScore = 0;
        $threshold = 0.4; // Minimum similarity score (40%)

        foreach ($pages as $page) {
            $normalizedTitle = ArabicNormalizer::normalize($page->title);
            $normalizedTitleWithoutAl = ArabicNormalizer::normalizeWithoutDefiniteArticle($page->title);

            // Calculate similarity with both normalized versions
            similar_text($normalizedQuery, $normalizedTitle, $score1);
            similar_text($normalizedQueryWithoutAl, $normalizedTitleWithoutAl, $score2);

            // Use the best score between the two comparisons
            $score = max($score1, $score2) / 100;

            if ($score > $bestScore && $score >= $threshold) {
                $bestScore = $score;
                $bestMatch = $page;
            }
        }

        return $bestMatch;
    }
}
