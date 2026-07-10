<?php

namespace App\Http\Controllers;

use App\Ai\Corpus\CorpusRetriever;
use App\Ai\Corpus\CorpusSearchResult;
use App\Http\Requests\SearchRequest;
use App\Settings\AiSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Public JSON endpoint for corpus search — the read side the search palette
 * consumes. Gated on the AiSettings search toggle (behind the master AI kill
 * switch): when disabled it answers 503 with `enabled: false` so the frontend
 * can distinguish "feature off" from "no results" without a shared prop.
 */
class SearchController extends Controller
{
    private const SNIPPET_LENGTH = 160;

    public function __invoke(SearchRequest $request, CorpusRetriever $retriever, AiSettings $settings): JsonResponse
    {
        if (! $settings->isFeatureEnabled('search')) {
            return response()->json([
                'enabled' => false,
                'results' => [],
                'message' => 'البحث الذكي غير متاح حالياً.',
            ], 503);
        }

        $results = $retriever->search(
            $request->string('q')->toString(),
            $request->integer('limit', 8),
        );

        return response()->json([
            'enabled' => true,
            'results' => $results
                ->map(fn (CorpusSearchResult $result): array => [
                    'title' => $result->title,
                    'slug' => $result->slug,
                    'heading' => $result->heading,
                    'snippet' => Str::limit($result->content, self::SNIPPET_LENGTH),
                    'score' => round($result->score, 4),
                ])
                ->values(),
        ]);
    }
}
