<?php

use App\Models\User;
use App\Settings\TelegramSettings;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->editor = User::factory()->create();
    $this->editor->assignRole('editor');
});

describe('authorization', function () {
    it('redirects guests to the login page', function () {
        $this->get('/manage/settings')->assertRedirect(route('manage.login'));
    });

    it('returns 403 for users without a panel role', function () {
        $this->actingAs(User::factory()->create());

        $this->get('/manage/settings')->assertForbidden();
    });

    it('allows editors to open and save the settings page (any panel user, parity with Filament)', function () {
        $this->actingAs($this->editor);

        $this->get('/manage/settings')->assertOk();

        $this->put('/manage/settings/telegram', [
            'allowed_chat_ids' => ['12345'],
            'auto_delete_messages' => true,
        ])->assertSessionHasNoErrors();

        expect(app(TelegramSettings::class)->page_management_allowed_chat_ids)->toBe(['12345']);
    });
});

describe('settings page', function () {
    it('renders the settings page with the current telegram settings', function () {
        $response = $this->actingAs($this->admin)->get('/manage/settings');

        $response->assertInertia(fn (Assert $page) => $page
            ->component('manage/settings/Index')
            ->where('telegram.allowed_chat_ids', [])
            ->where('telegram.auto_delete_messages', true)
        );
    });
});

describe('update', function () {
    it('saves valid chat ids including negative group ids', function () {
        $response = $this->actingAs($this->admin)
            ->from('/manage/settings')
            ->put('/manage/settings/telegram', [
                'allowed_chat_ids' => ['123456789', '-100987654321'],
                'auto_delete_messages' => true,
            ]);

        $response->assertRedirect('/manage/settings');
        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');

        expect(app(TelegramSettings::class)->page_management_allowed_chat_ids)
            ->toBe(['123456789', '-100987654321']);
    });

    it('normalizes integer chat ids to strings', function () {
        $this->actingAs($this->admin)
            ->putJson('/manage/settings/telegram', [
                'allowed_chat_ids' => [123456789, -42],
                'auto_delete_messages' => false,
            ])
            ->assertRedirect();

        expect(app(TelegramSettings::class)->page_management_allowed_chat_ids)->toBe(['123456789', '-42']);
    });

    it('allows clearing the list to permit all chats', function () {
        app(TelegramSettings::class)->fill(['page_management_allowed_chat_ids' => ['123']])->save();

        $this->actingAs($this->admin)
            ->put('/manage/settings/telegram', [
                'allowed_chat_ids' => [],
                'auto_delete_messages' => true,
            ])
            ->assertSessionHasNoErrors();

        expect(app(TelegramSettings::class)->page_management_allowed_chat_ids)->toBe([]);
    });

    it('persists the auto delete toggle', function () {
        $this->actingAs($this->admin)
            ->put('/manage/settings/telegram', [
                'allowed_chat_ids' => [],
                'auto_delete_messages' => false,
            ])
            ->assertSessionHasNoErrors();

        expect(app(TelegramSettings::class)->page_management_auto_delete_messages)->toBeFalse();
    });

    it('rejects invalid payloads with Arabic messages', function (array $payload, string $field, string $message) {
        $response = $this->actingAs($this->admin)->put('/manage/settings/telegram', $payload);

        $response->assertSessionHasErrors([$field => $message]);
    })->with([
        'non-numeric chat id' => [
            ['allowed_chat_ids' => ['abc'], 'auto_delete_messages' => true],
            'allowed_chat_ids.0',
            'معرّف المحادثة يجب أن يكون رقماً صحيحاً (قد يبدأ بإشارة سالبة للمجموعات).',
        ],
        'decimal chat id' => [
            ['allowed_chat_ids' => ['12.5'], 'auto_delete_messages' => true],
            'allowed_chat_ids.0',
            'معرّف المحادثة يجب أن يكون رقماً صحيحاً (قد يبدأ بإشارة سالبة للمجموعات).',
        ],
        'duplicate chat id' => [
            ['allowed_chat_ids' => ['123', '123'], 'auto_delete_messages' => true],
            'allowed_chat_ids.0',
            'معرّف المحادثة مكرر.',
        ],
        'missing chat ids' => [
            ['auto_delete_messages' => true],
            'allowed_chat_ids',
            'حقل معرّفات المحادثات مطلوب.',
        ],
        'missing toggle' => [
            ['allowed_chat_ids' => []],
            'auto_delete_messages',
            'حقل الحذف التلقائي مطلوب.',
        ],
    ]);

    it('does not change settings when validation fails', function () {
        $this->actingAs($this->admin)->put('/manage/settings/telegram', [
            'allowed_chat_ids' => ['not-a-number'],
            'auto_delete_messages' => true,
        ]);

        expect(app(TelegramSettings::class)->page_management_allowed_chat_ids)->toBe([]);
    });
});
