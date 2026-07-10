<?php

use App\Http\Controllers\Manage\LoginController;
use App\Http\Controllers\Manage\PrivateTutorController;
use App\Http\Controllers\Manage\PrivateTutorCourseController;
use App\Http\Controllers\Manage\TelegramSettingsController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::prefix('manage')->name('manage.')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');

    Route::middleware(['auth', 'manage.access'])->group(function () {
        Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

        Route::get('/', fn () => Inertia::render('manage/Dashboard'))->name('dashboard');

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

        Route::get('/settings', [TelegramSettingsController::class, 'edit'])->name('settings');
        Route::put('/settings/telegram', [TelegramSettingsController::class, 'update'])->name('settings.telegram.update');
    });
});
