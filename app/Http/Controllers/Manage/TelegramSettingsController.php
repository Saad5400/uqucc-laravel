<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\UpdateTelegramSettingsRequest;
use App\Settings\AiSettings;
use App\Settings\TelegramSettings;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TelegramSettingsController extends Controller
{
    /**
     * Show the settings page (a Telegram card and an AI card, each saved
     * explicitly through its own endpoint).
     */
    public function edit(TelegramSettings $settings, AiSettings $aiSettings): Response
    {
        return Inertia::render('manage/settings/Index', [
            'telegram' => [
                'allowed_chat_ids' => $settings->page_management_allowed_chat_ids,
                'auto_delete_messages' => $settings->page_management_auto_delete_messages,
            ],
            'ai' => [
                'ai_enabled' => $aiSettings->ai_enabled,
                'search_enabled' => $aiSettings->search_enabled,
                'assistant_enabled' => $aiSettings->assistant_enabled,
                'telegram_ai_enabled' => $aiSettings->telegram_ai_enabled,
                'admin_copilot_enabled' => $aiSettings->admin_copilot_enabled,
                'admin_assistant_enabled' => $aiSettings->admin_assistant_enabled,
                'chat_model' => $aiSettings->chat_model,
                'vision_model' => $aiSettings->vision_model,
                'embedding_model' => $aiSettings->embedding_model,
                'daily_budget_usd' => $aiSettings->daily_budget_usd,
                'per_session_rate_limit' => $aiSettings->per_session_rate_limit,
                'per_conversation_rate_limit' => $aiSettings->per_conversation_rate_limit,
            ],
        ]);
    }

    /**
     * Persist the Telegram settings.
     */
    public function update(UpdateTelegramSettingsRequest $request, TelegramSettings $settings): RedirectResponse
    {
        $settings->page_management_allowed_chat_ids = array_values($request->validated('allowed_chat_ids'));
        $settings->page_management_auto_delete_messages = $request->boolean('auto_delete_messages');
        $settings->save();

        return back()->with('success', 'تم حفظ إعدادات تيليجرام.');
    }
}
