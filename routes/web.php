<?php

use App\Http\Controllers\OgImageController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PrivateTutorController;
use App\Http\Controllers\ToolController;
use App\Http\Middleware\CacheResponse;
use Illuminate\Support\Facades\Route;

// Homepage - with full response caching
Route::get('/', [PageController::class, 'home'])
    ->name('home');

// OG Image generation endpoint (must come before catch-all route)
Route::get('/_og-image/{route?}', [OgImageController::class, 'generate'])
    ->where('route', '.*')
    ->name('og-image');

// Robots.txt (must come before catch-all route)
Route::get('/robots.txt', App\Http\Controllers\RobotsController::class);

// Tool routes (must come before catch-all route) - with response caching
Route::group(function () {
    Route::get('/adwat/almkafa', [ToolController::class, 'nextReward'])->name('tools.next-reward');
    Route::get('/adwat/hasbh-alhrman', [ToolController::class, 'deprivationCalculator'])->name('tools.deprivation-calculator');
    Route::get('/adwat/hasbh-almadl', [ToolController::class, 'gpaCalculator'])->name('tools.gpa-calculator');
    Route::get('/adwat/hasbh-altahwel', [ToolController::class, 'transferCalculator'])->name('tools.transfer-calculator');
    Route::get('/adwat/alkhosousieen', [PrivateTutorController::class, 'index'])->name('tools.private-tutors');
});

// Catch-all route for content pages (must be last!) - with full response caching
Route::get('/{slug}', [PageController::class, 'show'])
    ->where('slug', '.*')
    ->name('pages.show');
