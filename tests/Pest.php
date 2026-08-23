<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Pin an anonymous visitor's session id for a chat request. The framework
 * encrypts default cookies, so StartSession adopts this id exactly — which is
 * what keys conversation ownership, attachment ownership and the rate limiters.
 *
 * Shared here rather than in one test file because several chat suites need the
 * same identity: the endpoint contract, the attachment download door, and
 * anything else gating on "is this the session that sent it?".
 */
function withChatSession(string $sessionId)
{
    // withCredentials(): json helpers (getJson/postJson) only forward default
    // cookies when credentials are enabled.
    return test()
        ->withCredentials()
        ->withCookie((string) config('session.cookie'), $sessionId);
}

function chatSessionId(): string
{
    return Illuminate\Support\Str::random(40);
}
