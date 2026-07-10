<?php

use App\Http\Middleware\EnsureUserCanManage;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\TrackPageViews;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: [
            __DIR__.'/../routes/manage.php',
            __DIR__.'/../routes/web.php',
        ],
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            TrackPageViews::class,
        ]);

        $middleware->alias([
            'manage.access' => EnsureUserCanManage::class,
        ]);

        /*
         * TipTap documents (html_content, quick_response_message) must
         * round-trip byte-identically: trimming nested text nodes would eat
         * meaningful spaces (e.g. the trailing space before a bold span).
         * Title/slug on this endpoint are trimmed client-side / regex-validated.
         * (Path check, not routeIs(): this global middleware runs pre-routing.)
         */
        $middleware->trimStrings(except: [
            fn (Request $request) => $request->isMethod('PUT') && $request->is('manage/pages/*'),
        ]);

        $middleware->redirectGuestsTo(fn (Request $request) => route('manage.login'));

        // Trust all proxies to get real client IP from X-Forwarded-For headers
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR |
                     Request::HEADER_X_FORWARDED_HOST |
                     Request::HEADER_X_FORWARDED_PORT |
                     Request::HEADER_X_FORWARDED_PROTO |
                     Request::HEADER_X_FORWARDED_AWS_ELB
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
