<?php

use App\Http\Controllers\OgImageController;
use App\Http\Controllers\PageController;
use App\Http\Middleware\CacheResponse;
use Illuminate\Support\Facades\Route;

// Homepage - with full response caching
Route::get('/', [PageController::class, 'home'])
    ->middleware(CacheResponse::class)
    ->name('home');

// OG Image generation endpoint (must come before catch-all route)
Route::get('/_og-image/{route?}', [OgImageController::class, 'generate'])
    ->where('route', '.*')
    ->name('og-image');

// Robots.txt (must come before catch-all route)
Route::get('/robots.txt', App\Http\Controllers\RobotsController::class)
    ->middleware(CacheResponse::class);

// Tool routes (must come before catch-all route) - with response caching
Route::middleware(CacheResponse::class)->group(function () {
    Route::inertia('/adoat/almkafa', 'tools/NextRewardPage')->name('tools.next-reward');
    Route::inertia('/adoat/hasb-alhrman', 'tools/DeprivationCalculatorPage')->name('tools.deprivation-calculator');
    Route::inertia('/adwat/hasbh-almadl', 'tools/GpaCalculatorPage')->name('tools.gpa-calculator');
});

// Catch-all route for content pages (must be last!) - with full response caching
Route::get('/{slug}', [PageController::class, 'show'])
    ->where('slug', '.*')
    ->middleware(CacheResponse::class)
    ->name('pages.show');
