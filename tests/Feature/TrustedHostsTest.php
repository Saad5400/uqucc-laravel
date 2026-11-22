<?php

use App\Support\TrustedHosts;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;

afterEach(function () {
    Request::setTrustedHosts([]);
    putenv('TRUSTED_HOSTS');
});

it('trusts the APP_URL host, the health-check hosts and TRUSTED_HOSTS only', function () {
    config(['app.url' => 'https://uqucc.sb.sa']);
    putenv('TRUSTED_HOSTS=staging.uqucc.sb.sa, ');

    expect(TrustedHosts::patterns())->toBe([
        '^uqucc\.sb\.sa$',
        '^localhost$',
        '^127\.0\.0\.1$',
        '^staging\.uqucc\.sb\.sa$',
    ]);
});

it('answers only to those hosts once the patterns are applied', function () {
    config(['app.url' => 'https://uqucc.sb.sa']);
    Request::setTrustedHosts(TrustedHosts::patterns());

    expect(Request::create('https://uqucc.sb.sa/')->getHost())->toBe('uqucc.sb.sa')
        ->and(Request::create('http://localhost/up')->getHost())->toBe('localhost')
        ->and(fn () => Request::create('https://evil.example/')->getHost())->toThrow(SuspiciousOperationException::class)
        ->and(fn () => Request::create('https://uqucc.sb.sa.evil.example/')->getHost())->toThrow(SuspiciousOperationException::class);
});

it('registers the TrustHosts middleware globally', function () {
    expect(app(Kernel::class)->hasMiddleware(TrustHosts::class))->toBeTrue();
});
