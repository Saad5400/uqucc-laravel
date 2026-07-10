<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Artisan;

class CacheController extends Controller
{
    /**
     * Flush the application cache; it rebuilds lazily on upcoming visits.
     */
    public function clear(): RedirectResponse
    {
        Artisan::call('cache:clear');

        return back()->with('success', 'تم مسح الكاش');
    }
}
