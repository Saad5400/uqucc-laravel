<?php

use App\Models\TelegramChatSetting;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

describe('authorization', function () {
    it('redirects guests to the login page', function () {
        $this->get('/manage/telegram-chats')->assertRedirect(route('manage.login'));
    });

    it('returns 403 for users without a panel role', function () {
        $this->actingAs(User::factory()->create());

        $this->get('/manage/telegram-chats')->assertForbidden();
    });

    it('allows editors to open the page and toggle a chat (any panel user, parity with the previous admin resource)', function () {
        $editor = User::factory()->create();
        $editor->assignRole('editor');

        $chat = TelegramChatSetting::factory()->create();

        $this->actingAs($editor);

        $this->get('/manage/telegram-chats')->assertOk();

        $this->put("/manage/telegram-chats/{$chat->id}", ['ai_enabled' => true])
            ->assertSessionHasNoErrors();

        expect($chat->refresh()->ai_enabled)->toBeTrue();
    });
});

describe('index', function () {
    it('lists the chats with their settings', function () {
        $enabled = TelegramChatSetting::factory()->aiEnabled()->create(['title' => 'مجموعة الطلاب', 'type' => 'supergroup']);
        TelegramChatSetting::factory()->create(['updated_at' => now()->subDay()]);

        $this->actingAs($this->admin)
            ->get('/manage/telegram-chats')
            ->assertInertia(fn (Assert $page) => $page
                ->component('manage/telegram-chats/Index')
                ->count('chats', 2)
                ->where('chats.0.title', 'مجموعة الطلاب')
                ->where('chats.0.type', 'supergroup')
                ->where('chats.0.ai_enabled', true)
                ->where('chats.0.chat_id', (string) $enabled->chat_id)
            );
    });
});

describe('toggle', function () {
    it('enables and disables the assistant for a chat', function () {
        $chat = TelegramChatSetting::factory()->aiEnabled()->create();

        $this->actingAs($this->admin)
            ->put("/manage/telegram-chats/{$chat->id}", ['ai_enabled' => false])
            ->assertSessionHasNoErrors();

        expect($chat->refresh()->ai_enabled)->toBeFalse();
    });

    it('rejects a missing toggle value with an Arabic message', function () {
        $chat = TelegramChatSetting::factory()->create();

        $this->actingAs($this->admin)
            ->put("/manage/telegram-chats/{$chat->id}", [])
            ->assertSessionHasErrors(['ai_enabled' => 'حقل المساعد الذكي مطلوب.']);
    });
});

describe('reset conversation', function () {
    it('forgets the current conversation so the assistant starts fresh', function () {
        $chat = TelegramChatSetting::factory()->aiEnabled()->create(['conversation_id' => 'conv_123']);

        $this->actingAs($this->admin)
            ->post("/manage/telegram-chats/{$chat->id}/reset-conversation")
            ->assertSessionHas('success');

        expect($chat->refresh()->conversation_id)->toBeNull();
    });
});

describe('delete', function () {
    it('deletes the chat settings row', function () {
        $chat = TelegramChatSetting::factory()->create();

        $this->actingAs($this->admin)
            ->delete("/manage/telegram-chats/{$chat->id}")
            ->assertSessionHas('success');

        expect(TelegramChatSetting::query()->find($chat->id))->toBeNull();
    });
});
