<?php

namespace App\Http\Controllers;

use App\Services\SearchIndexService;
use Illuminate\Http\JsonResponse;

/**
 * The quick-search index (every visible page's title, breadcrumb and
 * preview), fetched by the search bar the first time a visitor uses it —
 * instead of riding along, ~100 KB of it, in the props of every page and
 * every Inertia visit.
 */
class SearchIndexController extends Controller
{
    public function __invoke(SearchIndexService $searchIndex): JsonResponse
    {
        return response()->json($searchIndex->getCachedIndex());
    }
}
