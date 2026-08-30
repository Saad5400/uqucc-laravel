<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Share card files
    |--------------------------------------------------------------------------
    |
    | Where the site's rendered share cards live and what they are — the OG
    | image a link preview shows, and the square card the Telegram bot sends
    | (App\Services\OgImageService).
    |
    | They are still called screenshots here, and in the cache key and in
    | `storage:cleanup --screenshots`, because that is the name the storage
    | directory has carried since they were browser screenshots of the page.
    | What the name refers to is now a designed card drawn by the Takumi
    | engine; nothing in this application photographs a page any more.
    |
    | The format is not configurable: the renderer emits PNG. Nothing here is an
    | env var either — these are the app's own conventions, not deployment
    | facts, and a mismatch between the extension on disk and the mime type on
    | the meta tag is a bug rather than an environment.
    |
    */

    'extension' => 'png',

    'mime_type' => 'image/png',

    'directory' => storage_path('app/public/screenshots'),

    // A card's URL is stable while its content is: the filename carries a
    // fingerprint of what the card says, so a week in a crawler's cache never
    // serves a stale one.
    'cache_control' => 'public, max-age=604800',
];
