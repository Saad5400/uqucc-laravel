<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\Testing\Fakes\FakeClient;
use Laravel\Octane\Testing\Fakes\FakeWorker;
use Symfony\Component\HttpFoundation\Response;

/*
| Runs requests for different visitors through ONE Octane worker — the same
| booted application serving request after request, as FrankenPHP worker
| mode does in production — and checks nothing of one visitor reaches the
| next: the signed-in user, the session, Inertia's shared props, a guest.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->alice = User::factory()->create(['name' => 'Alice', 'email' => 'alice@example.com', 'password' => 'alice-password']);
    $this->alice->assignRole('admin');

    $this->bob = User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'bob-password']);
    $this->bob->assignRole('editor');

    $this->inertiaVersion = (string) app(HandleInertiaRequests::class)->version(request());
    $this->testApplication = $this->app;
});

/**
 * Boot a fresh application the way the FrankenPHP worker does, sharing this
 * test's in-memory database connection.
 */
function bootOctaneWorker(): FakeWorker
{
    $pdo = DB::connection()->getPdo();

    $worker = new FakeWorker(new ApplicationFactory(base_path()), test()->octaneClient = new FakeClient([]));
    $worker->boot();
    restoreTestApplication();

    $worker->application()->make('db')->connection()->setPdo($pdo)->setReadPdo($pdo);

    return $worker;
}

/**
 * Booting and running the worker points the container and the facades at
 * the worker's application; hand them back to the test's own.
 */
function restoreTestApplication(): void
{
    Container::setInstance(test()->testApplication);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication(test()->testApplication);
}

/**
 * Serve one request on the worker and return its response.
 *
 * @param  array<string, string>  $cookies
 * @param  array<string, string>  $parameters
 * @param  array<string, string>  $server
 */
function serveOnWorker(FakeWorker $worker, string $method, string $uri, array $cookies = [], array $parameters = [], array $server = []): Response
{
    $client = test()->octaneClient;
    $client->requests = [Request::create($uri, $method, $parameters, $cookies, [], array_merge(['REMOTE_ADDR' => '203.0.113.7'], $server))];
    $client->responses = [];
    $worker->run();
    restoreTestApplication();

    expect($client->errors)->toBe([]);

    return $client->responses[0];
}

/**
 * @return array<string, string>
 */
function sessionCookieFrom(Response $response): array
{
    $cookies = [];

    foreach ($response->headers->getCookies() as $cookie) {
        $cookies[$cookie->getName()] = (string) $cookie->getValue();
    }

    return array_intersect_key($cookies, [config('session.cookie') => true]);
}

/**
 * The `auth.user` Inertia shares on the dashboard for this session cookie.
 *
 * @param  array<string, string>  $cookies
 * @return array<string, mixed>|null
 */
function dashboardUser(FakeWorker $worker, array $cookies, string $inertiaVersion): ?array
{
    $response = serveOnWorker($worker, 'GET', '/manage', $cookies, [], [
        'HTTP_X_INERTIA' => 'true',
        'HTTP_X_INERTIA_VERSION' => $inertiaVersion,
        'HTTP_ACCEPT' => 'text/html, application/xhtml+xml',
    ]);

    if ($response->getStatusCode() !== 200) {
        return ['status' => $response->getStatusCode(), 'location' => $response->headers->get('Location')];
    }

    return json_decode((string) $response->getContent(), true)['props']['auth']['user'];
}

it('keeps each visitor\'s user and session apart on one worker', function () {
    $worker = bootOctaneWorker();

    $aliceLogin = serveOnWorker($worker, 'POST', '/manage/login', [], ['email' => 'alice@example.com', 'password' => 'alice-password']);
    $bobLogin = serveOnWorker($worker, 'POST', '/manage/login', [], ['email' => 'bob@example.com', 'password' => 'bob-password']);

    expect($aliceLogin->getStatusCode())->toBe(302)
        ->and($bobLogin->getStatusCode())->toBe(302);

    $alice = sessionCookieFrom($aliceLogin);
    $bob = sessionCookieFrom($bobLogin);

    expect($alice)->not->toBe([])->and($bob)->not->toBe([])->and($alice)->not->toBe($bob);

    $first = dashboardUser($worker, $alice, $this->inertiaVersion);
    $second = dashboardUser($worker, $bob, $this->inertiaVersion);
    $guest = dashboardUser($worker, [], $this->inertiaVersion);
    $again = dashboardUser($worker, $alice, $this->inertiaVersion);

    expect($first['email'])->toBe('alice@example.com')
        ->and($first['roles'])->toBe(['admin'])
        ->and($second['email'])->toBe('bob@example.com')
        ->and($second['roles'])->toBe(['editor'])
        ->and($second['permissions'])->not->toContain('manage-users')
        ->and($guest['status'])->toBe(302)
        ->and($guest['location'])->toEndWith('/manage/login')
        ->and($again['email'])->toBe('alice@example.com');

    $worker->terminate();
});

it('does not carry a signed-in user into a later guest page on the same worker', function () {
    $worker = bootOctaneWorker();

    $alice = sessionCookieFrom(serveOnWorker($worker, 'POST', '/manage/login', [], ['email' => 'alice@example.com', 'password' => 'alice-password']));
    expect(dashboardUser($worker, $alice, $this->inertiaVersion)['email'])->toBe('alice@example.com');

    $page = serveOnWorker($worker, 'GET', '/csrf-cookie');

    expect($page->getStatusCode())->toBe(204)
        ->and(sessionCookieFrom($page))->not->toBe($alice)
        ->and(dashboardUser($worker, sessionCookieFrom($page), $this->inertiaVersion)['status'])->toBe(302);

    $worker->terminate();
});
