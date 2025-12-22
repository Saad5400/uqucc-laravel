<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Application Cache Settings
    |--------------------------------------------------------------------------
    |
    | Configure caching behavior for application-specific data like navigation
    | and search. These settings control time-to-live (TTL) values for various
    | cached components.
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
    ],
];
