<?php

namespace App\Services;

use App\Http\Middleware\ServeCachedPage;
use App\Http\Middleware\TrackPageViews;
use App\Models\Page;
use App\Models\PageViewStat;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Counts a view of a content page — the one seam both the web stack
 * ({@see TrackPageViews}) and a full-page cache hit served
 * before it ({@see ServeCachedPage}) record through.
 *
 * The write runs after the response has been sent (`defer()`), so a visitor
 * never waits on it. It still runs on the thread that served the request,
 * and under Octane that is one of a few fixed workers, so it is bounded: on
 * Postgres it gives up after {@see self::LOCK_TIMEOUT} waiting for a lock
 * (a migration locking the table during a deploy) and after
 * {@see self::STATEMENT_TIMEOUT} in all. The view is then dropped, as a
 * failed statistic always was, and so are the views of the next
 * {@see self::BACK_OFF_SECONDS} seconds without trying: while the table is
 * locked every write would only wait out the same bound. Hits that are not a person reading the page are not
 * counted: loopback requests (container health checks) and obvious bots,
 * crawlers, link-preview fetchers and HTTP libraries.
 */
class PageViewRecorder
{
    /**
     * User agents that are not a person viewing the page. Distinctive words
     * only — a false negative costs one inflated count, a false positive hides
     * a real reader (hence the exception for Cubot phones, "CUBOT X30").
     */
    private const NON_HUMAN_AGENT = '/(?<!cu)bot\b|bot\/|crawl|spider|slurp|scrape|preview|facebookexternalhit|^WhatsApp\/|headless|lighthouse|pingdom|uptime|monitor|curl\/|wget\/|python-|go-http-client|okhttp|axios\/|node-fetch|undici|guzzle|libwww|java\//i';

    private const LOOPBACK = ['127.0.0.1', '::1'];

    /** How long the write may wait for a lock, then how long it may run in all. */
    private const LOCK_TIMEOUT = '50ms';

    private const STATEMENT_TIMEOUT = '500ms';

    private const BACK_OFF_KEY = 'page_view_stats:write-paused';

    private const BACK_OFF_SECONDS = 10;

    /** Postgres lock_not_available and query_canceled: the bounds above firing. */
    private const TIMED_OUT = ['55P03', '57014'];

    public function record(Request $request, ?int $userId): void
    {
        $ipAddress = $request->ip();
        $userAgent = $request->userAgent();

        if (in_array($ipAddress, self::LOOPBACK, true) || $this->isNonHuman($userAgent)) {
            return;
        }

        $path = $request->path();
        $slug = $path === '/' ? '/' : '/'.$path;

        // The agent only decides bot-or-reader above; it is not stored.
        defer(fn () => $this->write($slug, $userId, $ipAddress));
    }

    /**
     * The deferred write. A bound firing is expected while a migration holds
     * the table, so it is a warning (at most one a minute), not an error for
     * every view; anything else is an error, and never an exception.
     */
    public function write(string $slug, ?int $userId, ?string $ipAddress): void
    {
        try {
            $pageId = Page::idForSlug($slug);

            if ($pageId === null || Cache::has(self::BACK_OFF_KEY)) {
                return;
            }

            $this->bounded(fn () => PageViewStat::track($pageId, $userId, $ipAddress));
        } catch (QueryException $e) {
            if (! in_array((string) $e->getCode(), self::TIMED_OUT, true)) {
                logger()->error('Failed to track page view', ['error' => $e->getMessage(), 'path' => $slug]);

                return;
            }

            Cache::put(self::BACK_OFF_KEY, true, self::BACK_OFF_SECONDS);

            if (Cache::add('page_view_stats:write-timed-out', true, now()->addMinute())) {
                Log::warning('Page views are being dropped: the page_view_stats write timed out (table locked?).', ['error' => $e->getMessage()]);
            }
        } catch (Throwable $e) {
            // Never let a statistic break anything.
            logger()->error('Failed to track page view', ['error' => $e->getMessage(), 'path' => $slug]);
        }
    }

    /**
     * Run the write with Postgres' lock and statement timeouts set for its
     * own transaction only (set_config(..., true) is SET LOCAL), so the
     * long-lived connection keeps its defaults for whatever comes next.
     */
    private function bounded(callable $write): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $write();

            return;
        }

        DB::transaction(function () use ($write): void {
            DB::select('SELECT set_config(?, ?, true), set_config(?, ?, true)', [
                'lock_timeout', self::LOCK_TIMEOUT,
                'statement_timeout', self::STATEMENT_TIMEOUT,
            ]);

            $write();
        });
    }

    private function isNonHuman(?string $userAgent): bool
    {
        return $userAgent === null || trim($userAgent) === '' || preg_match(self::NON_HUMAN_AGENT, $userAgent) === 1;
    }
}
