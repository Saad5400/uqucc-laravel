<?php

use App\Http\Middleware\EnsureUserCanManage;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\TrackPageViews;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Psr\Log\LogLevel;

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
        /*
         * Transient upstream AI failures are infrastructure noise, not code
         * defects: a request timeout (ConnectionException) or an empty /
         * rate-limited / overloaded provider response (AiException) should stay
         * in the daily log for trend-watching but must NOT page the Telegram
         * error channel, which fires at `error` and above.
         *
         * Running out of OpenRouter credits IS actionable, so it is pinned back
         * to `error` first — mapLogLevel() matches on insertion order, and
         * InsufficientCreditsException extends AiException.
         */
        $exceptions->level(InsufficientCreditsException::class, LogLevel::ERROR);
        $exceptions->level(ConnectionException::class, LogLevel::WARNING);
        $exceptions->level(AiException::class, LogLevel::WARNING);
    })->create();
