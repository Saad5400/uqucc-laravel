<?php

namespace App\Support;

/**
 * The Host values the app answers to (TrustHosts, bootstrap/app.php).
 *
 * Without a list, any Host / X-Forwarded-Host a request carries becomes
 * request()->getHost(): it flows into absolute URLs, the share-card text and
 * the page-cache key. Only the APP_URL host is trusted, plus `localhost` /
 * 127.0.0.1 for the in-container health check, plus TRUSTED_HOSTS
 * (comma-separated extra hostnames) for anything else that must reach the
 * app. Everything else answers 400.
 */
final class TrustedHosts
{
    /** @return list<string> anchored regex patterns, as TrustHosts expects */
    public static function patterns(): array
    {
        $hosts = [
            parse_url((string) config('app.url'), PHP_URL_HOST) ?: null,
            'localhost',
            '127.0.0.1',
            ...explode(',', (string) env('TRUSTED_HOSTS', '')),
        ];

        $hosts = array_values(array_unique(array_filter(array_map(
            fn (?string $host): string => strtolower(trim((string) $host)),
            $hosts,
        ))));

        return array_map(fn (string $host): string => '^'.preg_quote($host).'$', $hosts);
    }
}
