<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\UpdateTelegramChatSettingRequest;
use App\Models\TelegramChatSetting;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin visibility (and override) for the bot's per-chat AI activation.
 * Rows are created by the /ai_on and /ai_off commands inside Telegram; this
 * page lists them with a toggle so operators can enable or disable the
 * assistant for any chat from the panel.
 */
class TelegramChatSettingController extends Controller
{
    /**
     * List the chats (few rows — searched and filtered client-side).
     */
    public function index(): Response
    {
        return Inertia::render('manage/telegram-chats/Index', [
            'chats' => TelegramChatSetting::query()
                ->latest('updated_at')
                ->latest('id')
                ->get()
                ->map(fn (TelegramChatSetting $chat): array => [
                    'id' => $chat->id,
                    'chat_id' => (string) $chat->chat_id,
                    'title' => $chat->title,
                    'type' => $chat->type,
                    'ai_enabled' => $chat->ai_enabled,
                    'enabled_by' => $chat->enabled_by,
                    'has_conversation' => filled($chat->conversation_id),
                    'updated_at' => $chat->updated_at?->toISOString(),
                ]),
        ]);
    }

    /**
     * Toggle the assistant for a chat (silent save, like the list toggle it
     * replaces — the switch itself is the feedback).
     */
    public function update(UpdateTelegramChatSettingRequest $request, TelegramChatSetting $chat): RedirectResponse
    {
        $chat->update(['ai_enabled' => $request->boolean('ai_enabled')]);

        return back();
    }

    /**
     * Forget the assistant's current conversation so it starts fresh in
     * this chat.
     */
    public function resetConversation(TelegramChatSetting $chat): RedirectResponse
    {
        $chat->update(['conversation_id' => null]);

        return back()->with('success', 'تمت إعادة تعيين المحادثة.');
    }

    /**
     * Delete a chat's settings row (the assistant falls back to inactive
     * until the chat re-enables it).
     */
    public function destroy(TelegramChatSetting $chat): RedirectResponse
    {
        $chat->delete();

        return back()->with('success', 'تم حذف المحادثة.');
    }
}
