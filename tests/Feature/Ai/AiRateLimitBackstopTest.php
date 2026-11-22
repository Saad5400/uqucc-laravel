<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/** A request from $ip that presents an established session cookie. */
function aiLimiterRequest(string $ip, string $sessionId): Request
{
    $request = Request::create('/ai/chat', 'POST', server: ['REMOTE_ADDR' => $ip]);
    $request->cookies->set((string) config('session.cookie'), $sessionId);

    $session = app('session')->driver();
    $session->setId($sessionId);
    $request->setLaravelSession($session);

    return $request;
}

/** @return list<Limit> */
function aiLimits(string $limiter, Request $request): array
{
    return array_values((array) RateLimiter::limiter($limiter)($request));
}

it('caps the assistant chat per IP even when the client rotates sessions', function () {
    $first = aiLimits('ai-chat', aiLimiterRequest('203.0.113.7', str_repeat('a', 40)));
    $second = aiLimits('ai-chat', aiLimiterRequest('203.0.113.7', str_repeat('b', 40)));

    // The per-session burst key changes with the session...
    expect($first[0]->key)->not->toBe($second[0]->key)
        ->and($first[0]->maxAttempts)->toBe(5);

    // ...the per-IP backstop does not.
    expect($first[1]->key)->toBe('ai-chat-ip:203.0.113.7')
        ->and($second[1]->key)->toBe($first[1]->key)
        ->and($first[1]->maxAttempts)->toBe(30);
});

it('caps the corpus search per IP even when the client rotates sessions', function () {
    $limits = aiLimits('ai-search', aiLimiterRequest('203.0.113.7', str_repeat('c', 40)));

    expect($limits[0]->maxAttempts)->toBe(20)
        ->and($limits[1]->key)->toBe('ai-search-ip:203.0.113.7')
        ->and($limits[1]->maxAttempts)->toBe(60);
});

it('answers 429 once one IP passes the chat backstop across fresh sessions', function () {
    foreach (range(1, 30) as $attempt) {
        RateLimiter::hit(md5('ai-chat'.'ai-chat-ip:203.0.113.9'), 60);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->postJson('/ai/chat', ['message' => 'مرحبا'])
        ->assertStatus(429);
});

it('puts two IPv6 addresses of one /64 in the same bucket, and another /64 in its own', function () {
    $first = aiLimits('ai-chat', aiLimiterRequest('2001:db8:abcd:12::1', str_repeat('d', 40)));
    $sibling = aiLimits('ai-chat', aiLimiterRequest('2001:db8:abcd:12:ffff:ffff:ffff:fffe', str_repeat('e', 40)));
    $neighbour = aiLimits('ai-chat', aiLimiterRequest('2001:db8:abcd:13::1', str_repeat('f', 40)));

    expect($first[1]->key)->toBe('ai-chat-ip:2001:db8:abcd:12::/64')
        ->and($sibling[1]->key)->toBe($first[1]->key)
        ->and($neighbour[1]->key)->not->toBe($first[1]->key);

    $search = aiLimits('ai-search', aiLimiterRequest('2001:db8:abcd:12::99', str_repeat('g', 40)));

    expect($search[1]->key)->toBe('ai-search-ip:2001:db8:abcd:12::/64')
        ->and($search[1]->maxAttempts)->toBe(60);
});

it('keys IPv4 (and IPv4-mapped IPv6) by the full address', function () {
    $plain = aiLimits('ai-chat', aiLimiterRequest('198.51.100.4', str_repeat('h', 40)));
    $mapped = aiLimits('ai-chat', aiLimiterRequest('::ffff:198.51.100.4', str_repeat('i', 40)));
    $other = aiLimits('ai-chat', aiLimiterRequest('198.51.100.5', str_repeat('j', 40)));

    expect($plain[1]->key)->toBe('ai-chat-ip:198.51.100.4')
        ->and($mapped[1]->key)->toBe($plain[1]->key)
        ->and($other[1]->key)->not->toBe($plain[1]->key);
});

it('caps chat turns per address per day', function () {
    $limit = RateLimiter::limiter('ai-chat-daily')(aiLimiterRequest('203.0.113.10', str_repeat('k', 40)));

    expect($limit->maxAttempts)->toBe(300)
        ->and($limit->decaySeconds)->toBe(86400)
        ->and($limit->key)->toBe('ai-chat-ip-day:203.0.113.10');

    foreach (range(1, 300) as $turn) {
        RateLimiter::hit(md5('ai-chat-daily'.'ai-chat-ip-day:203.0.113.10'), 86400);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->postJson('/ai/chat', ['message' => 'مرحبا'])
        ->assertStatus(429);
});
