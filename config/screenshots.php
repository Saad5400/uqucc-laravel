<?php

$format = env('SCREENSHOT_FORMAT', 'jpeg');

return [
    /*
    |--------------------------------------------------------------------------
    | Screenshot Settings
    |--------------------------------------------------------------------------
    |
    | Centralized configuration for generated screenshots to avoid hardcoding
    | values across the application.
    |
    */
    'format' => $format,
    'extension' => env('SCREENSHOT_EXTENSION', $format === 'jpeg' ? 'jpg' : $format),
    'quality' => env('SCREENSHOT_QUALITY', 70), // Lower quality for faster generation
    'directory' => env('SCREENSHOT_DIRECTORY', storage_path('app/public/screenshots')),
    'cache_control' => env('SCREENSHOT_CACHE_CONTROL', 'public, max-age=604800'),

    'mime_types' => [
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ],
];
