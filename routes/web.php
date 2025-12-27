<?php

use App\Http\Controllers\PageController;
use Illuminate\Support\Facades\Route;

// Homepage
Route::get('/', [PageController::class, 'home'])->name('home');

// Tool routes (must come before catch-all route)
Route::inertia('/adoat/almkafa', 'tools/NextRewardPage')->name('tools.next-reward');
Route::inertia('/adoat/hasb-alhrman', 'tools/DeprivationCalculatorPage')->name('tools.deprivation-calculator');
Route::inertia('/adwat/hasbh-almadl', 'tools/GpaCalculatorPage')->name('tools.gpa-calculator');

// Catch-all route for content pages (must be last!)
Route::get('/{slug}', [PageController::class, 'show'])
    ->where('slug', '.*')
    ->name('pages.show');
