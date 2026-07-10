<?php

use App\Http\Controllers\Manage\LoginController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::prefix('manage')->name('manage.')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');

    Route::middleware(['auth', 'manage.access'])->group(function () {
        Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

        Route::get('/', fn () => Inertia::render('manage/Dashboard'))->name('dashboard');
    });
});
