<?php

use App\Http\Controllers\PageController;
use Illuminate\Support\Facades\Route;

// Homepage
Route::get('/', [PageController::class, 'home'])->name('home');

// Tool routes (must come before catch-all route)
Route::inertia('/adoat/almkafa', 'tools/NextRewardPage')->name('tools.next-reward');
Route::inertia('/adoat/hasb-alhrman', 'tools/DeprivationCalculatorPage')->name('tools.deprivation-calculator');
Route::inertia('/adoat/hasb-almaadl', 'tools/GpaCalculatorPage')->name('tools.gpa-calculator');
Route::inertia('/adoat/jadwal-alhaqiqa', 'tools/TruthTablePage')->name('tools.truth-table');

// Catch-all route for content pages (must be last!)
Route::get('/{slug}', [PageController::class, 'show'])
    ->where('slug', '.*')
    ->name('pages.show');
