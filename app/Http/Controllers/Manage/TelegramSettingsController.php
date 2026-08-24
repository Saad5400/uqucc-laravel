<?php

namespace App\Http\Controllers\Manage;

use App\Ai\ModelRegistry;
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
    public function edit(TelegramSettings $settings, AiSettings $aiSettings, ModelRegistry $models): Response
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
                'daily_budget_usd' => $aiSettings->daily_budget_usd,
                'per_session_rate_limit' => $aiSettings->per_session_rate_limit,
                'per_conversation_rate_limit' => $aiSettings->per_conversation_rate_limit,
            ],
            // Read-only: models are config, not operator state (ai-kit
            // docs/DECISIONS.md #26). Shown rather than hidden so an operator
            // can still see what is answering students without an editable
            // field that used to override config invisibly.
            'models' => [
                'chat' => $models->chat(),
                'chat_reasoning_effort' => $models->chatReasoningEffort(),
                'vision' => $models->vision(),
                'embedding' => (string) config('ai.embeddings.model', ''),
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
