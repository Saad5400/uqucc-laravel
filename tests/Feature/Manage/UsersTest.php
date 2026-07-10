<?php

use App\Models\Page;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->editor = User::factory()->create();
    $this->editor->assignRole('editor');
});

/**
 * A user who may manage users but not assign roles (no such built-in role
 * exists, so it is an editor with a direct `manage-users` permission).
 */
function userManagerWithoutRoleAssignment(): User
{
    $user = User::factory()->create();
    $user->assignRole('editor');
    $user->givePermissionTo('manage-users');

    return $user;
}

describe('authorization', function () {
    it('redirects guests to the login page', function () {
        $this->get('/manage/users')->assertRedirect(route('manage.login'));
    });

    it('blocks editors from the users workspace', function () {
        $this->actingAs($this->editor)->get('/manage/users')->assertForbidden();
    });

    it('blocks editors from every user mutation', function () {
        $target = User::factory()->create();

        $this->actingAs($this->editor);

        $this->post('/manage/users', ['name' => 'مستخدم'])->assertForbidden();
        $this->put("/manage/users/{$target->id}", ['name' => 'جديد'])->assertForbidden();
        $this->delete("/manage/users/{$target->id}")->assertForbidden();
    });

    it('allows admins to open the users workspace', function () {
        $this->actingAs($this->admin)->get('/manage/users')->assertOk();
    });

    it('allows a manage-users holder without assign-roles to open the workspace', function () {
        $this->actingAs(userManagerWithoutRoleAssignment())->get('/manage/users')->assertOk();
    });
});

describe('index', function () {
    it('shares users with roles, verification state, telegram id, and authored pages count', function () {
        $author = User::factory()->unverified()->create(['telegram_id' => '123456789']);
        $author->assignRole('editor');
        $author->pages()->attach(Page::factory()->create(), ['order' => 1]);

        $response = $this->actingAs($this->admin)->get('/manage/users');

        $response->assertInertia(fn (Assert $page) => $page
            ->component('manage/users/Index')
            ->count('users', 3)
            ->where('users.0.id', $this->admin->id)
            ->where('users.0.roles', ['admin'])
            ->where('users.0.verified', true)
            ->where('users.0.pages_count', 0)
            ->where('users.2.id', $author->id)
            ->where('users.2.roles', ['editor'])
            ->where('users.2.verified', false)
            ->where('users.2.telegram_id', '123456789')
            ->where('users.2.pages_count', 1)
            ->where('roleOptions', ['admin', 'editor'])
        );
    });
});

describe('create', function () {
    it('creates a verified user with a hashed password and assigns the roles', function () {
        $response = $this->actingAs($this->admin)
            ->from('/manage/users')
            ->post('/manage/users', [
                'name' => 'مستخدم جديد',
                'email' => 'new@example.com',
                'password' => 'secret-password',
                'password_confirmation' => 'secret-password',
                'roles' => ['editor'],
            ]);

        $response->assertRedirect('/manage/users');
        $response->assertSessionHasNoErrors();

        $user = User::query()->where('email', 'new@example.com')->first();
        expect($user)->not->toBeNull()
            ->and($user->name)->toBe('مستخدم جديد')
            ->and($user->email_verified_at)->not->toBeNull()
            ->and(Hash::check('secret-password', $user->password))->toBeTrue()
            ->and($user->getRoleNames()->all())->toBe(['editor']);
    });

    it('silently ignores roles when the acting user cannot assign roles', function () {
        $this->actingAs(userManagerWithoutRoleAssignment())
            ->post('/manage/users', [
                'name' => 'بدون أدوار',
                'email' => 'no-roles@example.com',
                'password' => 'secret-password',
                'password_confirmation' => 'secret-password',
                'roles' => ['admin'],
            ])
            ->assertSessionHasNoErrors();

        expect(User::query()->where('email', 'no-roles@example.com')->first()->getRoleNames())->toBeEmpty();
    });

    it('rejects invalid payloads with Arabic messages', function (array $payload, string $field, string $message) {
        $valid = [
            'name' => 'مستخدم',
            'email' => 'valid@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ];

        $response = $this->actingAs($this->admin)->post('/manage/users', array_merge($valid, $payload));

        $response->assertSessionHasErrors([$field => $message]);
    })->with([
        'missing name' => [['name' => null], 'name', 'حقل الاسم مطلوب.'],
        'invalid email' => [['email' => 'not-an-email'], 'email', 'يجب إدخال بريد إلكتروني صالح.'],
        'missing password' => [['password' => null], 'password', 'حقل كلمة المرور مطلوب.'],
        'unconfirmed password' => [['password_confirmation' => 'different'], 'password', 'تأكيد كلمة المرور غير متطابق.'],
        'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password', 'يجب أن تكون كلمة المرور ٨ أحرف على الأقل.'],
        'unknown role' => [['roles' => ['superadmin']], 'roles.0', 'أحد الأدوار المحددة غير موجود.'],
    ]);

    it('rejects a duplicate email', function () {
        $this->actingAs($this->admin)
            ->post('/manage/users', [
                'name' => 'مكرر',
                'email' => $this->editor->email,
                'password' => 'secret-password',
                'password_confirmation' => 'secret-password',
            ])
            ->assertSessionHasErrors(['email' => 'هذا البريد الإلكتروني مستخدم بالفعل.']);
    });
});

describe('update', function () {
    it('updates the user attributes without touching the password when none is sent', function () {
        $target = User::factory()->create();
        $originalHash = $target->password;

        $this->actingAs($this->admin)
            ->put("/manage/users/{$target->id}", ['name' => 'اسم محدث', 'email' => 'updated@example.com'])
            ->assertSessionHasNoErrors();

        expect($target->fresh())
            ->name->toBe('اسم محدث')
            ->email->toBe('updated@example.com')
            ->password->toBe($originalHash);
    });

    it('rehashes the password when a new one is sent', function () {
        $target = User::factory()->create();

        $this->actingAs($this->admin)
            ->put("/manage/users/{$target->id}", [
                'name' => $target->name,
                'email' => $target->email,
                'password' => 'new-secret-password',
                'password_confirmation' => 'new-secret-password',
            ])
            ->assertSessionHasNoErrors();

        expect(Hash::check('new-secret-password', $target->fresh()->password))->toBeTrue();
    });

    it('accepts an empty password without changing the stored one', function () {
        $target = User::factory()->create();
        $originalHash = $target->password;

        $this->actingAs($this->admin)
            ->put("/manage/users/{$target->id}", [
                'name' => $target->name,
                'email' => $target->email,
                'password' => '',
                'password_confirmation' => '',
            ])
            ->assertSessionHasNoErrors();

        expect($target->fresh()->password)->toBe($originalHash);
    });

    it('clears the verification timestamp when the switch is turned off', function () {
        $target = User::factory()->create();

        $this->actingAs($this->admin)
            ->put("/manage/users/{$target->id}", ['name' => $target->name, 'email' => $target->email, 'verified' => false])
            ->assertSessionHasNoErrors();

        expect($target->fresh()->email_verified_at)->toBeNull();
    });

    it('sets the verification timestamp when the switch is turned on', function () {
        $target = User::factory()->unverified()->create();

        $this->actingAs($this->admin)
            ->put("/manage/users/{$target->id}", ['name' => $target->name, 'email' => $target->email, 'verified' => true])
            ->assertSessionHasNoErrors();

        expect($target->fresh()->email_verified_at)->not->toBeNull();
    });

    it('keeps the original verification timestamp when the switch stays on', function () {
        $target = User::factory()->create(['email_verified_at' => '2025-01-01 00:00:00']);

        $this->actingAs($this->admin)
            ->put("/manage/users/{$target->id}", ['name' => $target->name, 'email' => $target->email, 'verified' => true])
            ->assertSessionHasNoErrors();

        expect($target->fresh()->email_verified_at->toDateTimeString())->toBe('2025-01-01 00:00:00');
    });

    it('updates the author profile fields', function () {
        $target = User::factory()->create();

        $this->actingAs($this->admin)
            ->put("/manage/users/{$target->id}", [
                'name' => $target->name,
                'email' => $target->email,
                'username' => 'author-handle',
                'url' => 'https://author.example.com',
                'avatar' => 'https://author.example.com/avatar.png',
            ])
            ->assertSessionHasNoErrors();

        expect($target->fresh())
            ->username->toBe('author-handle')
            ->url->toBe('https://author.example.com')
            ->avatar->toBe('https://author.example.com/avatar.png');
    });

    it('syncs roles when the acting user can assign roles', function () {
        $target = User::factory()->create();
        $target->assignRole('editor');

        $this->actingAs($this->admin)
            ->put("/manage/users/{$target->id}", ['name' => $target->name, 'email' => $target->email, 'roles' => ['admin']])
            ->assertSessionHasNoErrors();

        expect($target->fresh()->getRoleNames()->all())->toBe(['admin']);
    });

    it('silently ignores roles when the acting user cannot assign roles', function () {
        $target = User::factory()->create();
        $target->assignRole('editor');

        $this->actingAs(userManagerWithoutRoleAssignment())
            ->put("/manage/users/{$target->id}", ['name' => $target->name, 'email' => $target->email, 'roles' => ['admin']])
            ->assertSessionHasNoErrors();

        expect($target->fresh()->getRoleNames()->all())->toBe(['editor']);
    });

    it('blocks an admin from removing their own admin role', function () {
        $response = $this->actingAs($this->admin)
            ->put("/manage/users/{$this->admin->id}", [
                'name' => $this->admin->name,
                'email' => $this->admin->email,
                'roles' => ['editor'],
            ]);

        $response->assertSessionHasErrors(['roles' => 'لا يمكنك إزالة دور المدير من حسابك.']);
        expect($this->admin->fresh()->hasRole('admin'))->toBeTrue();
    });

    it('lets an admin keep their own admin role while editing themselves', function () {
        $this->actingAs($this->admin)
            ->put("/manage/users/{$this->admin->id}", [
                'name' => 'اسمي الجديد',
                'email' => $this->admin->email,
                'roles' => ['admin', 'editor'],
            ])
            ->assertSessionHasNoErrors();

        expect($this->admin->fresh())
            ->name->toBe('اسمي الجديد')
            ->hasRole('admin')->toBeTrue();
    });

    it('rejects an email already used by another user but accepts the current one', function () {
        $target = User::factory()->create();

        $this->actingAs($this->admin)
            ->put("/manage/users/{$target->id}", ['name' => $target->name, 'email' => $this->editor->email])
            ->assertSessionHasErrors(['email' => 'هذا البريد الإلكتروني مستخدم بالفعل.']);

        $this->actingAs($this->admin)
            ->put("/manage/users/{$target->id}", ['name' => $target->name, 'email' => $target->email])
            ->assertSessionHasNoErrors();
    });

    it('rejects invalid author profile fields', function () {
        $target = User::factory()->create();

        $this->actingAs($this->admin)
            ->put("/manage/users/{$target->id}", [
                'name' => $target->name,
                'email' => $target->email,
                'url' => 'not-a-url',
            ])
            ->assertSessionHasErrors(['url' => 'يجب إدخال رابط صالح يبدأ بـ https:// أو http://.']);
    });
});

describe('delete', function () {
    it('deletes the user', function () {
        $target = User::factory()->create();

        $this->actingAs($this->admin)->delete("/manage/users/{$target->id}")->assertSessionHasNoErrors();

        expect(User::query()->find($target->id))->toBeNull();
    });

    it('blocks a user from deleting themselves', function () {
        $response = $this->actingAs($this->admin)->delete("/manage/users/{$this->admin->id}");

        $response->assertSessionHasErrors(['user' => 'لا يمكنك حذف حسابك.']);
        expect(User::query()->find($this->admin->id))->not->toBeNull();
    });

    it('keeps authored pages and only detaches the author pivot', function () {
        $author = User::factory()->create();
        $page = Page::factory()->create();
        $author->pages()->attach($page, ['order' => 1]);

        $this->actingAs($this->admin)->delete("/manage/users/{$author->id}");

        expect(User::query()->find($author->id))->toBeNull()
            ->and(Page::query()->find($page->id))->not->toBeNull()
            ->and(DB::table('page_user')->where('user_id', $author->id)->exists())->toBeFalse();
    });

    it('returns 404 for a missing user', function () {
        $this->actingAs($this->admin)->delete('/manage/users/999')->assertNotFound();
    });
});
