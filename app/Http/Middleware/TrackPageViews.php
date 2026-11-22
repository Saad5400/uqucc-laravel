<?php

namespace App\Http\Middleware;

use App\Services\PageViewRecorder;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackPageViews
{
    public function __construct(private readonly PageViewRecorder $pageViews) {}

    /**
     * Count a successful GET of a content page (written after the response is
     * sent — see PageViewRecorder).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethod('GET') && $response->isSuccessful()) {
            $this->pageViews->record($request, $request->user()?->id);
        }

        return $response;
    }
}
