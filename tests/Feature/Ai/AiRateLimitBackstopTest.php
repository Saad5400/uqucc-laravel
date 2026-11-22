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
