<?php

namespace App\Support;

use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Ssr\HttpGateway;
use Inertia\Ssr\Response;

/**
 * Inertia's SSR gateway with a deadline. The stock one posts each page to the
 * SSR server with the HTTP client's default 30s timeout, so a stuck or
 * overloaded Node process held every page render (and the PHP thread under
 * it) for 30s before falling back to client rendering. This one gives up after
 * `inertia.ssr.timeout` seconds and falls back the same way: the page still
 * renders, in the browser. Under Octane's small worker pool a 30s wait
 * would stall every visitor behind it; in classic mode it saves the thread.
 *
 * After a timeout or a refused connection the server is skipped for
 * {@see self::BACK_OFF_SECONDS}, so an outage costs one thread one deadline,
 * not every render its own wait; the next render after that tries again.
 *
 * A render that should have been server-rendered but fell back is marked on
 * its request ({@see self::fellBack()}), so the full-page cache does not keep
 * that client-rendered shell for an hour after SSR is back.
 */
class BoundedSsrGateway extends HttpGateway
{
    public const BACK_OFF_KEY = 'inertia-ssr:unreachable';

    private const BACK_OFF_SECONDS = 10;

    private const FELL_BACK = 'inertia.ssr_fell_back';

    /**
     * Whether this request's page was meant to be server-rendered but went
     * out client-rendered (SSR down, slow or skipped). Request-scoped, so it
     * is per request under Octane as well.
     */
    public static function fellBack(Request $request): bool
    {
        return $request->attributes->get(self::FELL_BACK) === true;
    }

    /**
     * @param  array<string, mixed>  $page
     */
    public function dispatch(array $page): ?Response
    {
        if (! $this->shouldDispatch()) {
            return null;
        }

        if (Cache::has(self::BACK_OFF_KEY)) {
            return $this->fallBack();
        }

        $timeout = (float) config('inertia.ssr.timeout', 2.5);

        try {
            $response = Http::connectTimeout(min(1.0, $timeout))
                ->timeout($timeout)
                ->post($this->getUrl('/render'), $page)
                ->throw()
                ->json();
        } catch (Exception $e) {
            if ($e instanceof StrayRequestException) {
                throw $e;
            }

            if ($e instanceof ConnectionException) {
                Cache::put(self::BACK_OFF_KEY, true, self::BACK_OFF_SECONDS);
            }

            return $this->fallBack();
        }

        if (! is_array($response)) {
            return $this->fallBack();
        }

        return new Response(implode("\n", $response['head']), $response['body']);
    }

    private function fallBack(): null
    {
        request()->attributes->set(self::FELL_BACK, true);

        return null;
    }
}
