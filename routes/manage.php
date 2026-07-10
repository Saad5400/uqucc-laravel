<?php

use App\Http\Controllers\Manage\ActivityLogController;
use App\Http\Controllers\Manage\AdminAssistantController;
use App\Http\Controllers\Manage\AiSettingsController;
use App\Http\Controllers\Manage\CacheController;
use App\Http\Controllers\Manage\CorpusDocumentController;
use App\Http\Controllers\Manage\DashboardController;
use App\Http\Controllers\Manage\LoginController;
use App\Http\Controllers\Manage\PageAuthorsController;
use App\Http\Controllers\Manage\PageController;
use App\Http\Controllers\Manage\PageCopilotController;
use App\Http\Controllers\Manage\PageUploadController;
use App\Http\Controllers\Manage\PrivateTutorController;
use App\Http\Controllers\Manage\PrivateTutorCourseController;
use App\Http\Controllers\Manage\TelegramChatSettingController;
use App\Http\Controllers\Manage\TelegramSettingsController;
use App\Http\Controllers\Manage\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('manage')->name('manage.')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');

    Route::middleware(['auth', 'manage.access'])->group(function () {
        Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::post('/cache/clear', [CacheController::class, 'clear'])->name('cache.clear');

        Route::get('/pages', [PageController::class, 'index'])->name('pages.index');
        Route::post('/pages', [PageController::class, 'store'])->name('pages.store');
        Route::post('/pages/reorder', [PageController::class, 'reorder'])->name('pages.reorder');
        Route::post('/pages/uploads', [PageUploadController::class, 'store'])->name('pages.uploads.store');
        Route::get('/pages/{page}/edit', [PageController::class, 'edit'])->name('pages.edit')->withTrashed();
        Route::put('/pages/{page}', [PageController::class, 'update'])->name('pages.update')->withTrashed();
        Route::delete('/pages/{page}', [PageController::class, 'destroy'])->name('pages.destroy');
        Route::post('/pages/{page}/restore', [PageController::class, 'restore'])->name('pages.restore')->withTrashed();
        Route::delete('/pages/{page}/force', [PageController::class, 'forceDestroy'])->name('pages.force-destroy')->withTrashed();
        Route::put('/pages/{page}/authors', [PageAuthorsController::class, 'update'])->name('pages.authors.update');

        Route::post('/pages/{page}/copilot/improve-text', [PageCopilotController::class, 'improveText'])->name('pages.copilot.improve-text')->withTrashed();
        Route::post('/pages/{page}/copilot/draft-section', [PageCopilotController::class, 'draftSection'])->name('pages.copilot.draft-section')->withTrashed();
        Route::post('/pages/{page}/copilot/seo-meta', [PageCopilotController::class, 'generateSeoMeta'])->name('pages.copilot.seo-meta')->withTrashed();

        Route::get('/assistant', [AdminAssistantController::class, 'index'])->name('assistant.index');
        Route::post('/assistant/chat', [AdminAssistantController::class, 'send'])->middleware('throttle:15,1')->name('assistant.send');
        Route::get('/assistant/chat/{conversation}', [AdminAssistantController::class, 'show'])->name('assistant.show');
        Route::post('/assistant/proposals/{proposal}/confirm', [AdminAssistantController::class, 'confirm'])->name('assistant.proposals.confirm');
        Route::post('/assistant/proposals/{proposal}/reject', [AdminAssistantController::class, 'reject'])->name('assistant.proposals.reject');

        Route::get('/corpus', [CorpusDocumentController::class, 'index'])->name('corpus.index');
        Route::post('/corpus', [CorpusDocumentController::class, 'store'])->name('corpus.store');
        Route::get('/corpus/{document}/edit', [CorpusDocumentController::class, 'edit'])->name('corpus.edit');
        Route::put('/corpus/{document}', [CorpusDocumentController::class, 'update'])->name('corpus.update');
        Route::post('/corpus/{document}/reextract', [CorpusDocumentController::class, 'reextract'])->name('corpus.reextract');
        Route::post('/corpus/{document}/reingest', [CorpusDocumentController::class, 'reingest'])->name('corpus.reingest');
        Route::delete('/corpus/{document}', [CorpusDocumentController::class, 'destroy'])->name('corpus.destroy');

        Route::get('/telegram-chats', [TelegramChatSettingController::class, 'index'])->name('telegram-chats.index');
        Route::put('/telegram-chats/{chat}', [TelegramChatSettingController::class, 'update'])->name('telegram-chats.update');
        Route::post('/telegram-chats/{chat}/reset-conversation', [TelegramChatSettingController::class, 'resetConversation'])->name('telegram-chats.reset-conversation');
        Route::delete('/telegram-chats/{chat}', [TelegramChatSettingController::class, 'destroy'])->name('telegram-chats.destroy');

        Route::middleware('can:manage-users')->group(function () {
            Route::get('/users', [UserController::class, 'index'])->name('users.index');
            Route::post('/users', [UserController::class, 'store'])->name('users.store');
            Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
        });

        Route::middleware('can:manage-private-tutors')->group(function () {
            Route::get('/tutors', [PrivateTutorController::class, 'index'])->name('tutors.index');
            Route::post('/tutors', [PrivateTutorController::class, 'store'])->name('tutors.store');
            Route::post('/tutors/reorder', [PrivateTutorController::class, 'reorder'])->name('tutors.reorder');
            Route::put('/tutors/{tutor}', [PrivateTutorController::class, 'update'])->name('tutors.update');
            Route::delete('/tutors/{tutor}', [PrivateTutorController::class, 'destroy'])->name('tutors.destroy');

            Route::post('/courses', [PrivateTutorCourseController::class, 'store'])->name('courses.store');
            Route::post('/courses/reorder', [PrivateTutorCourseController::class, 'reorder'])->name('courses.reorder');
            Route::put('/courses/{course}', [PrivateTutorCourseController::class, 'update'])->name('courses.update');
            Route::delete('/courses/{course}', [PrivateTutorCourseController::class, 'destroy'])->name('courses.destroy');
        });

        Route::get('/activity', [ActivityLogController::class, 'index'])
            ->middleware('can:view-activity-logs')
            ->name('activity.index');

        Route::get('/settings', [TelegramSettingsController::class, 'edit'])->name('settings');
        Route::put('/settings/telegram', [TelegramSettingsController::class, 'update'])->name('settings.telegram.update');
        Route::put('/settings/ai', [AiSettingsController::class, 'update'])->name('settings.ai.update');
    });
});
