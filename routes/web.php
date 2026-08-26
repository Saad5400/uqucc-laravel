<?php

use App\Http\Controllers\Ai\ChatAttachmentController;
use App\Http\Controllers\Ai\ChatController;
use App\Http\Controllers\OgImageController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PrivateTutorController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\StudentGroupController;
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
    Route::get('/adwat/jdwal-alsawab', [ToolController::class, 'truthTable'])->name('tools.truth-table');
    Route::get('/adwat/sorh-albtaqa', [ToolController::class, 'studentPhoto'])->name('tools.student-photo');
    Route::get('/adwat/alkhosousieen', [PrivateTutorController::class, 'index'])->name('tools.private-tutors');
});

// Student Telegram groups directory (must come before catch-all route) - with
// response caching. Which supervisor a visitor is handed is randomized in the
// browser, so one cached HTML response still spreads requests across everyone.
Route::get('/qroubat', [StudentGroupController::class, 'index'])
    ->middleware(CacheResponse::class)
    ->name('student-groups');

// Truth table generation endpoint (JSON; used by the tool page) - rate limited, never cached
Route::post('/adwat/jdwal-alsawab', [ToolController::class, 'generateTruthTable'])
    ->middleware('throttle:60,1')
    ->name('tools.truth-table.generate');

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
// Feature/budget/daily-quota gating happens inside the controllers against AiSettings + ai-kit BudgetGuard.
Route::middleware('throttle:ai-chat')->group(function () {
    Route::post('/ai/chat', [ChatController::class, 'send'])->name('ai.chat.send');
    Route::post('/ai/chat/attachments', ChatAttachmentController::class)->name('ai.chat.attachments.store');
    Route::get('/ai/chat/{conversation}', [ChatController::class, 'show'])->name('ai.chat.show');
});

// Resuming or stopping a turn the visitor ALREADY paid for, deliberately
// OUTSIDE the burst limiter above.
//
// `ai-chat` is 5 requests per minute per session, and a single long turn can
// need several of them: the opening POST, then one reconnect every time the
// kit's tail closes at its `max_stream_seconds` ceiling, plus a stop. Counting
// those against the same five would 429 the reconnect ladder — which strands a
// turn that has already been generated and charged for, the exact failure
// resumability exists to prevent. The manage-side pair is excluded for the
// same reason.
//
// Nothing is given away by excluding them: both read the turn buffer instead of
// opening a turn, so they spend no model call, no daily quota slot and no
// budget, and both 404 anything the visitor's own session does not own. The
// `/ai/chat/turns/…` prefix is what keeps the single-segment {conversation}
// route from swallowing them — never the group, which has no bearing on
// matching.
Route::get('/ai/chat/turns/{turn}/stream', [ChatController::class, 'stream'])->name('ai.chat.stream');
Route::post('/ai/chat/turns/{turn}/cancel', [ChatController::class, 'cancel'])->name('ai.chat.cancel');

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
