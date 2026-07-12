<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Pgvector\Laravel\Schema as PgvectorSchema;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (config('app.env') === 'production') {
            URL::forceScheme('https');
        }

        $this->registerPgvectorSchemaSupport();
        $this->configureRateLimiting();

        Gate::define('review-changes', fn (User $user): bool => $user->canReviewChanges());
    }

    /**
     * Named limiters consumed via the `throttle:` route middleware.
     *
     * `ai-search` throttles the public corpus search endpoint per visitor:
     * keyed by the established session (only when the request presented a
     * session cookie — a brand-new session id would differ on every request
     * and defeat the limiter), falling back to the client IP.
     *
     * `mcp` throttles the public MCP endpoint (routes/ai.php). MCP clients
     * are stateless HTTP callers without a session cookie, so it keys by
     * client IP only; the budget is higher than `ai-search` because one
     * agent session legitimately chains several JSON-RPC calls.
     *
     * `ai-chat` is the assistant chat's BURST limiter (same session-first
     * key as `ai-search`); the operator's per-session DAILY quota and the
     * daily spend budget are enforced in code by the chat controller.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('ai-search', function (Request $request): Limit {
            $sessionId = $request->hasSession() && $request->cookies->has((string) config('session.cookie'))
                ? $request->session()->getId()
                : null;

            return Limit::perMinute(20)->by('ai-search:'.($sessionId ?? (string) $request->ip()));
        });

        RateLimiter::for('mcp', function (Request $request): Limit {
            return Limit::perMinute(30)->by('mcp:'.(string) $request->ip());
        });

        RateLimiter::for('ai-chat', function (Request $request): Limit {
            $sessionId = $request->hasSession() && $request->cookies->has((string) config('session.cookie'))
                ? $request->session()->getId()
                : null;

            return Limit::perMinute(5)->by('ai-chat:'.($sessionId ?? (string) $request->ip()));
        });
    }

    /**
     * Register pgvector's `vector` column type without its service provider.
     *
     * PgvectorServiceProvider is excluded via composer `dont-discover` because it
     * force-loads an unguarded `CREATE EXTENSION` migration that breaks sqlite
     * (local dev and tests). Extension creation is handled instead by the
     * pgsql-guarded migration in database/migrations.
     */
    private function registerPgvectorSchemaSupport(): void
    {
        PgvectorSchema::register();
    }
}
