<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;
use Pgvector\Laravel\Schema as PgvectorSchema;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Per-IP ceilings behind the per-session AI limits. A session is free to
     * mint (drop the cookie, GET /csrf-cookie), so the session key alone let
     * one client rotate sessions and drain the shared daily AI budget for
     * everyone. Generous enough for a campus NAT; meaningful only because
     * TRUSTED_PROXIES makes request()->ip() the real client.
     */
    private const AI_CHAT_PER_IP = 30;

    private const AI_SEARCH_PER_IP = 60;

    /**
     * Chat turns per client address per day, on top of the per-minute burst
     * limits: sized for a shared campus / mobile-carrier address, low enough
     * that one address cannot spend the whole daily budget.
     */
    private const AI_CHAT_PER_IP_PER_DAY = 300;

    /** The `ai-chat` per-minute refusal; must NOT contain «اليومي» (that locks the page for the day). */
    public const AI_CHAT_BURST_LIMIT_MESSAGE = 'محاولات كثيرة خلال وقت قصير، انتظر دقيقة ثم أعد المحاولة.';

    /** The `ai-chat-daily` refusal; must keep the word «اليومي» (see that limiter). */
    public const AI_CHAT_DAILY_LIMIT_MESSAGE = 'وصلت إلى الحد اليومي لرسائل المساعد من هذا الاتصال. عد غداً وسيسعدنا مساعدتك.';

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

        /*
         * league/oauth2-server refuses to load an OAuth key whose file
         * permissions are looser than 600/660 and throws on every token/MCP
         * request. In the container the storage volume can surface the keys as
         * 0666, which 500s the whole /mcp/admin surface. The keys are only
         * reachable inside the container, so we relax the permission *warning*
         * check here — token signing/verification is unaffected.
         */
        Passport::$validateKeyPermissions = false;

        /*
         * The consent screen an MCP client's OAuth authorization request lands
         * on after the moderator signs in — the "approve / deny this agent"
         * step of the authorization-code flow (routes/ai.php). Uses the app's
         * own RTL Arabic view instead of Passport's default English one.
         */
        Passport::authorizationView(fn (array $parameters) => view('mcp.authorize', $parameters));
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
     * `mcp-admin` throttles the authenticated moderation MCP endpoint. Once
     * OAuth-authenticated there is a stable identity, so it keys by user id
     * (falling back to IP) with a higher budget than the public server.
     *
     * `ai-chat` is the assistant chat's BURST limiter (same session-first
     * key as `ai-search`); the operator's per-session DAILY quota and the
     * daily spend budget are enforced in code by the chat controller.
     *
     * Both AI limiters also carry a per-IP backstop (AI_CHAT_PER_IP /
     * AI_SEARCH_PER_IP) so rotating sessions does not reset them.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('ai-search', function (Request $request): array {
            $sessionId = $request->hasSession() && $request->cookies->has((string) config('session.cookie'))
                ? $request->session()->getId()
                : null;

            return [
                Limit::perMinute(20)->by('ai-search:'.($sessionId ?? self::clientAddressKey($request))),
                Limit::perMinute(self::AI_SEARCH_PER_IP)->by('ai-search-ip:'.self::clientAddressKey($request)),
            ];
        });

        RateLimiter::for('mcp', function (Request $request): Limit {
            return Limit::perMinute(30)->by('mcp:'.(string) $request->ip());
        });

        RateLimiter::for('mcp-admin', function (Request $request): Limit {
            return Limit::perMinute(60)->by('mcp-admin:'.($request->user()?->getAuthIdentifier() ?? (string) $request->ip()));
        });

        RateLimiter::for('ai-chat', function (Request $request): array {
            $sessionId = $request->hasSession() && $request->cookies->has((string) config('session.cookie'))
                ? $request->session()->getId()
                : null;

            // A burst refusal is answered in Arabic as "slow down" — never with
            // «اليومي», which would lock the assistant page for the day.
            $slowDown = fn (Request $request, array $headers) => response()->json([
                'message' => self::AI_CHAT_BURST_LIMIT_MESSAGE,
            ], 429, $headers);

            return [
                Limit::perMinute(5)->by('ai-chat:'.($sessionId ?? self::clientAddressKey($request)))->response($slowDown),
                Limit::perMinute(self::AI_CHAT_PER_IP)->by('ai-chat-ip:'.self::clientAddressKey($request))->response($slowDown),
            ];
        });

        // Only on the turn-opening POST (routes/web.php): reading a
        // conversation or uploading an attachment must not use up the day.
        //
        // Answered in Arabic with «اليومي» in the message: that is what the
        // assistant page (AssistantPage.vue) keys its daily-limit state on, so
        // the student sees "come back tomorrow" instead of retrying into
        // Laravel's English "Too Many Attempts.".
        RateLimiter::for('ai-chat-daily', function (Request $request): Limit {
            return Limit::perDay(self::AI_CHAT_PER_IP_PER_DAY)
                ->by('ai-chat-ip-day:'.self::clientAddressKey($request))
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => self::AI_CHAT_DAILY_LIMIT_MESSAGE,
                ], 429, $headers));
        });
    }

    /**
     * The client address a per-IP limit is keyed by: an IPv4 address as is,
     * an IPv6 address by its /64. One IPv6 subscriber is routinely handed a
     * whole /64, so keying by the full address let a single client rotate
     * through billions of "different" IPs. IPv4-mapped IPv6 counts as IPv4.
     */
    public static function clientAddressKey(Request $request): string
    {
        $ip = (string) $request->ip();
        $packed = @inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }

        if (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff")) {
            return (string) inet_ntop(substr($packed, 12));
        }

        return inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)).'/64';
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
