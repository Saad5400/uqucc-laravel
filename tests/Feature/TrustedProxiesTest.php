<?php

/*
| TRUSTED_PROXIES (bootstrap/app.php) pins the proxy chain in production:
| Traefik's network plus Cloudflare's ranges. With the chain trusted, the
| client IP is the first X-Forwarded-For hop that is NOT a trusted proxy —
| the real visitor — instead of the Cloudflare edge that per-IP limits and
| page-view identities would otherwise lump everyone under.
*/

function bootWithTrustedProxies(?string $value): void
{
    if ($value === null) {
        unset($_SERVER['TRUSTED_PROXIES'], $_ENV['TRUSTED_PROXIES']);
    } else {
        $_SERVER['TRUSTED_PROXIES'] = $_ENV['TRUSTED_PROXIES'] = $value;
    }

    test()->refreshApplication();
}

/**
 * @return array{ip: string|null, secure: bool}
 */
function clientIpVia(string $remoteAddr, string $forwardedFor): array
{
    test()->withServerVariables(['REMOTE_ADDR' => $remoteAddr])
        ->withHeaders(['X-Forwarded-For' => $forwardedFor, 'X-Forwarded-Proto' => 'https'])
        ->get('/robots.txt')
        ->assertOk();

    $request = app('request');

    return ['ip' => $request->ip(), 'secure' => $request->isSecure()];
}

afterEach(function (): void {
    bootWithTrustedProxies(null);
});

it('resolves the real client behind a trusted Cloudflare edge and Traefik', function (): void {
    // Traefik's docker network + a Cloudflare range, as prod sets them.
    bootWithTrustedProxies('10.0.1.0/24, 173.245.48.0/20');

    // Client → Cloudflare edge (173.245.48.10) → Traefik (10.0.1.5) → app.
    expect(clientIpVia('10.0.1.5', '203.0.113.7, 173.245.48.10'))
        ->toBe(['ip' => '203.0.113.7', 'secure' => true]);
});

it('does not let a client spoof its IP through an untrusted hop', function (): void {
    bootWithTrustedProxies('10.0.1.0/24,173.245.48.0/20');

    // The visitor forged "1.2.3.4" in front; the first untrusted hop wins.
    expect(clientIpVia('10.0.1.5', '1.2.3.4, 198.51.100.20, 173.245.48.10')['ip'])->toBe('198.51.100.20');
});

it('ignores forwarded headers from a peer outside the trusted list', function (): void {
    bootWithTrustedProxies('10.0.1.0/24');

    expect(clientIpVia('192.0.2.9', '203.0.113.7'))
        ->toBe(['ip' => '192.0.2.9', 'secure' => false]);
});

it('keeps trusting the direct peer when the variable is blank or *', function (?string $value): void {
    bootWithTrustedProxies($value);

    // Only the peer is trusted: the right-most forwarded hop is the client.
    expect(clientIpVia('10.0.1.5', '203.0.113.7, 173.245.48.10')['ip'])->toBe('173.245.48.10');
})->with(['unset' => null, 'blank' => '', 'star' => '*']);
