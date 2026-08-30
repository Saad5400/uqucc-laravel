<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'telegram' => [
        'token' => env('TELEGRAM_BOT_TOKEN'),
    ],

    'ocr_space' => [
        'api_key' => env('OCR_SPACE_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Takumi image renderer
    |--------------------------------------------------------------------------
    |
    | The cards App\Support\TakumiRenderer draws are laid out by a Node script
    | (scripts/takumi-render.mjs). Only the interpreter is configurable, and
    | only because the deployment image installs Node through Nix and PHP-FPM
    | does not always see it on PATH (nixpacks.toml exports NODE_BINARY for it).
    | Everything else the render needs (the fonts, the packages) is in the repo.
    |
    */

    'takumi' => [
        'node_binary' => env('NODE_BINARY', 'node'),
    ],

    'google_analytics' => [
        'id' => env('GOOGLE_ANALYTICS_ID', 'G-D6V76T469N'),
    ],

];
