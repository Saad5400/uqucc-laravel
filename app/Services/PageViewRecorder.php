<?php

namespace App\Services;

use App\Http\Middleware\ServeCachedPage;
use App\Http\Middleware\TrackPageViews;
use App\Models\Page;
use App\Models\PageViewStat;
use Illuminate\Http\Request;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Counts a view of a content page — the one seam both the web stack
 * ({@see TrackPageViews}) and a full-page cache hit served
 * before it ({@see ServeCachedPage}) record through.
 *
 * The write runs after the response has been sent (`defer()`), so a visitor
 * never waits on it. Hits that are not a person reading the page are not
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
        defer(function () use ($slug, $userId, $ipAddress): void {
            try {
                $pageId = Page::idForSlug($slug);

                if ($pageId !== null) {
                    PageViewStat::track($pageId, $userId, $ipAddress);
                }
            } catch (Throwable $e) {
                // Never let a statistic break anything.
                logger()->error('Failed to track page view', ['error' => $e->getMessage(), 'path' => $slug]);
            }
        });
    }

    private function isNonHuman(?string $userAgent): bool
    {
        return $userAgent === null || trim($userAgent) === '' || preg_match(self::NON_HUMAN_AGENT, $userAgent) === 1;
    }
}
