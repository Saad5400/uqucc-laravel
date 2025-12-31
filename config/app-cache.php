<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Application Cache Settings
    |--------------------------------------------------------------------------
    |
    | Configure caching behavior for application-specific data like navigation,
    | search, pages, and more. These settings control time-to-live (TTL) values
    | for various cached components.
    |
    | All TTL values are in seconds.
    |
    */

    'navigation' => [
        // Cache duration for navigation tree (in seconds)
        // Default: 3600 (1 hour)
        'ttl' => env('CACHE_NAVIGATION_TTL', 3600),
    ],

    'search' => [
        // Cache duration for search data (in seconds)
        // Default: 3600 (1 hour)
        'ttl' => env('CACHE_SEARCH_TTL', 3600),
    ],

    'quick_responses' => [
        // Cache duration for Telegram-ready quick responses (in seconds)
        // Default: 3600 (1 hour)
        'ttl' => env('CACHE_QUICK_RESPONSES_TTL', 3600),
    ],

    'screenshots' => [
        // Cache duration for page screenshots (in seconds)
        // Default: 604800 (7 days) - screenshots are expensive to generate
        'ttl' => env('CACHE_SCREENSHOTS_TTL', 604800),
    ],

    'pages' => [
        // Cache duration for individual page data (in seconds)
        // Default: 1800 (30 minutes)
        'ttl' => env('CACHE_PAGES_TTL', 1800),

        // Cache duration for page breadcrumbs (in seconds)
        // Default: 3600 (1 hour)
        'breadcrumbs_ttl' => env('CACHE_BREADCRUMBS_TTL', 3600),

        // Cache duration for catalog pages list (in seconds)
        // Default: 1800 (30 minutes)
        'catalog_ttl' => env('CACHE_CATALOG_TTL', 1800),

        // Cache duration for full HTTP response cache (in seconds)
        'response_ttl' => env('CACHE_RESPONSE_TTL', 3600),
    ],

    'telegram' => [
        // Cache duration for Telegram handler states (in seconds)
        // Default: 600 (10 minutes)
        'state_ttl' => env('CACHE_TELEGRAM_STATE_TTL', 600),

        // Cache duration for login state (in seconds)
        // Default: 300 (5 minutes)
        'login_state_ttl' => env('CACHE_TELEGRAM_LOGIN_STATE_TTL', 300),

        // Cache duration for external attachments (in seconds)
        // Default: 86400 (1 day)
        'external_attachments_ttl' => env('CACHE_EXTERNAL_ATTACHMENTS_TTL', 86400),
    ],

    /*
     |--------------------------------------------------------------------------
     | Cache Keys
     |--------------------------------------------------------------------------
     |
     | Centralized cache key definitions to avoid typos and ensure consistency
     | across the application.
     |
     */

    'keys' => [
        'navigation_tree' => 'navigation_tree',
        'search_data' => 'search_data',
        'quick_responses' => 'quick_responses',
        'screenshot' => 'screenshot', // Base key, will be appended with page slug and version

        // Page-related cache keys
        'page' => 'page', // Base key, will be appended with page id and version
        'page_breadcrumbs' => 'page_breadcrumbs', // Base key for breadcrumbs
        'catalog_pages' => 'catalog_pages', // Base key for catalog
        'response_cache' => 'response_cache', // Full HTTP response cache

        // Telegram-related cache keys
        'telegram_page_mgmt_state' => 'telegram_page_mgmt_state_',
        'telegram_login_state' => 'telegram_login_state_',
        'external_attachment' => 'external_attachment:',
    ],
];
