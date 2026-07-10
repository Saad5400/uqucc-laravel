<?php

use App\Http\Controllers\Manage\ActivityLogController;
use App\Http\Controllers\Manage\CacheController;
use App\Http\Controllers\Manage\DashboardController;
use App\Http\Controllers\Manage\LoginController;
use App\Http\Controllers\Manage\PageAuthorsController;
use App\Http\Controllers\Manage\PageController;
use App\Http\Controllers\Manage\PageUploadController;
use App\Http\Controllers\Manage\PrivateTutorController;
use App\Http\Controllers\Manage\PrivateTutorCourseController;
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
    });
});
