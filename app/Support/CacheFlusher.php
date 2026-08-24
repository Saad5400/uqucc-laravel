<?php

namespace App\Support;

use Illuminate\Cache\RedisStore;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Forgets cache entries by key pattern — how the app invalidates families of
 * keys it cannot enumerate (`page:*`, `response_cache:*`, ...).
 *
 * Getting at those keys takes some care, and doing it wrong fails SILENTLY —
 * a flush that matches nothing looks exactly like a flush that had nothing to
 * do, so the app keeps serving stale pages for a full TTL after every edit.
 * Two traps, both of which this class exists to keep in one place:
 *
 *  - `Cache::getRedis()` returns the Redis MANAGER, which proxies to the
 *    `default` connection. The cache lives on the `cache` connection, a
 *    different database, so the manager scans an unrelated keyspace. The
 *    store's own `connection()` is the only correct handle.
 *  - keys carry TWO prefixes: the cache store's (`...-cache-`) and, on top of
 *    that, the Redis connection's (`...-database-`). The pattern we pass must
 *    carry the store prefix and NOT the connection prefix, because the client
 *    adds the latter itself; the keys we get back carry both, so the logical
 *    key `Cache::forget()` wants is whatever follows the store prefix.
 *
 * Pattern matching needs a keyspace scan, which only the Redis store offers —
 * on any other store this is a no-op, so callers must not rely on it as their
 * only invalidation of a key they can name exactly.
 */
class CacheFlusher
{
    /**
     * Forget every cache entry whose key matches one of the given glob
     * patterns, e.g. `page:*`.
     *
     * KEYS, not SCAN: the MATCH option of SCAN is not a key argument, so the
     * Redis client does not prefix it, and building that prefix by hand
     * differs between predis and phpredis. The app cache is a small dedicated
     * database and this runs on writes, not reads.
     */
    public static function forgetMatching(string ...$patterns): void
    {
        $store = Cache::store()->getStore();

        if (! $store instanceof RedisStore) {
            return;
        }

        $prefix = $store->getPrefix();
        $connection = $store->connection();

        foreach ($patterns as $pattern) {
            foreach ($connection->keys($prefix.$pattern) as $key) {
                Cache::forget(Str::after($key, $prefix));
            }
        }
    }
}
