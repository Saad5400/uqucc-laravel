<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);
});

function createManageUser(?string $role = null): User
{
    $user = User::factory()->create();

    if ($role !== null) {
        $user->assignRole($role);
    }

    return $user;
}

describe('login page', function () {
    it('renders the login page for guests', function () {
        $response = $this->get(route('manage.login'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->component('manage/auth/Login'));
    });
});

describe('login', function () {
    it('logs in an admin and redirects to the dashboard', function () {
        $user = createManageUser('admin');

        $response = $this->post(route('manage.login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('manage.dashboard'));
        $this->assertAuthenticatedAs($user);
    });

    it('logs in an editor', function () {
        $user = createManageUser('editor');

        $response = $this->post(route('manage.login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('manage.dashboard'));
        $this->assertAuthenticatedAs($user);
    });

    it('supports remember me', function () {
        $user = createManageUser('admin');

        $response = $this->post(route('manage.login.store'), [
            'email' => $user->email,
            'password' => 'password',
            'remember' => true,
        ]);

        $this->assertAuthenticatedAs($user);

        $rememberCookie = collect($response->headers->getCookies())
            ->first(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_'));
        expect($rememberCookie)->not->toBeNull();
    });

    it('redirects to the intended admin url after login', function () {
        $user = createManageUser('admin');

        $this->get('/manage')->assertRedirect(route('manage.login'));

        $response = $this->post(route('manage.login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('manage.dashboard'));
    });

    it('rejects invalid credentials with an Arabic error', function () {
        $user = createManageUser('admin');

        $response = $this->from(route('manage.login'))->post(route('manage.login.store'), [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect(route('manage.login'));
        $response->assertSessionHasErrors(['email' => 'بيانات الدخول غير صحيحة.']);
        $this->assertGuest();
    });

    it('rejects a user without an admin or editor role using the same generic error', function () {
        $user = createManageUser();

        $response = $this->from(route('manage.login'))->post(route('manage.login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('manage.login'));
        $response->assertSessionHasErrors(['email' => 'بيانات الدخول غير صحيحة.']);
        $this->assertGuest();
    });

    it('validates the login payload', function (array $payload, string $field) {
        $response = $this->post(route('manage.login.store'), $payload);

        $response->assertSessionHasErrors($field);
        $this->assertGuest();
    })->with([
        'missing email' => [['password' => 'password'], 'email'],
        'invalid email' => [['email' => 'not-an-email', 'password' => 'password'], 'email'],
        'missing password' => [['email' => 'admin@example.com'], 'password'],
    ]);

    it('rate limits login after five failed attempts', function () {
        $user = createManageUser('admin');

        foreach (range(1, 5) as $attempt) {
            $this->post(route('manage.login.store'), [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);
        }

        $response = $this->post(route('manage.login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors('email');
        expect(session('errors')->first('email'))->toContain('عدد محاولات الدخول تجاوز الحد المسموح');
        $this->assertGuest();
    });
});

describe('dashboard access', function () {
    it('redirects guests to the login page', function () {
        $this->get('/manage')->assertRedirect(route('manage.login'));
    });

    it('returns 403 for an authenticated user without an admin or editor role', function () {
        $this->actingAs(createManageUser());

        $this->get('/manage')->assertForbidden();
    });

    it('renders the dashboard for an admin', function () {
        $this->actingAs(createManageUser('admin'));

        $response = $this->get('/manage');

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->component('manage/Dashboard'));
    });

    it('renders the dashboard for an editor', function () {
        $this->actingAs(createManageUser('editor'));

        $this->get('/manage')->assertOk();
    });

    it('shares the authenticated user with roles and permissions via Inertia', function () {
        $this->actingAs(createManageUser('editor'));

        $response = $this->get('/manage');

        $response->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.roles', ['editor'])
            ->where('auth.user.permissions', fn ($permissions) => collect($permissions)->sort()->values()->all() === ['edit-content', 'view-activity-logs'])
        );
    });
});

describe('logout', function () {
    it('logs the user out and redirects to the login page', function () {
        $this->actingAs(createManageUser('admin'));

        $response = $this->post(route('manage.logout'));

        $response->assertRedirect(route('manage.login'));
        $this->assertGuest();
    });

    it('rejects guests attempting to logout', function () {
        $this->post(route('manage.logout'))->assertRedirect(route('manage.login'));
    });
});
