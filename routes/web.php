<?php

use App\Http\Controllers\Ai\ChatAttachmentController;
use App\Http\Controllers\Ai\ChatController;
use App\Http\Controllers\OgImageController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PrivateTutorController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ToolController;
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
    Route::get('/adwat/almkafa', [ToolController::class, 'nextReward'])->name('tools.next-reward');
    Route::get('/adwat/hasbh-alhrman', [ToolController::class, 'deprivationCalculator'])->name('tools.deprivation-calculator');
    Route::get('/adwat/hasbh-almadl', [ToolController::class, 'gpaCalculator'])->name('tools.gpa-calculator');
    Route::get('/adwat/hasbh-altahwel', [ToolController::class, 'transferCalculator'])->name('tools.transfer-calculator');
    Route::get('/adwat/alkhosousieen', [PrivateTutorController::class, 'index'])->name('tools.private-tutors');
});

// AI corpus search endpoint (JSON; must come before catch-all route) - rate limited, never cached
Route::get('/bahth', SearchController::class)
    ->middleware('throttle:ai-search')
    ->name('search');

// Original file of a ready corpus document (regulations PDF) — the citable
// source URL for AI answers drawn from uploaded documents.
Route::get('/mstnd/{document}', App\Http\Controllers\CorpusDocumentFileController::class)
    ->whereNumber('document')
    ->name('documents.show');

// AI assistant chat (SSE + JSON; must come before catch-all route) - rate limited, never cached.
// Feature/budget/daily-quota gating happens inside the controllers against AiSettings + SpendLedger.
Route::middleware('throttle:ai-chat')->group(function () {
    Route::post('/ai/chat', [ChatController::class, 'send'])->name('ai.chat.send');
    Route::post('/ai/chat/attachments', ChatAttachmentController::class)->name('ai.chat.attachments.store');
    Route::get('/ai/chat/{conversation}', [ChatController::class, 'show'])->name('ai.chat.show');
});

// AI assistant chat page (must come before catch-all route) - with response caching.
// Always renders; the chat endpoints report the disabled state at runtime.
Route::get('/almosaed', App\Http\Controllers\AssistantPageController::class)
    ->middleware(CacheResponse::class)
    ->name('assistant');

// The previous admin panel lived at /admin — permanently redirect bookmarks and
// bot edit-links to the /manage panel (must come before the catch-all route)
Route::permanentRedirect('/admin', '/manage');
Route::permanentRedirect('/admin/{any}', '/manage')->where('any', '.*');

// Catch-all route for content pages (must be last!) - with full response caching
Route::get('/{slug}', [PageController::class, 'show'])
    ->where('slug', '.*')
    ->middleware(CacheResponse::class)
    ->name('pages.show');
