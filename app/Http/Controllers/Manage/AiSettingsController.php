<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\UpdateAiSettingsRequest;
use App\Settings\AiSettings;
use Illuminate\Http\RedirectResponse;

class AiSettingsController extends Controller
{
    /**
     * Persist the AI settings card on the settings page (explicit save,
     * mirroring the Telegram card).
     */
    public function update(UpdateAiSettingsRequest $request, AiSettings $settings): RedirectResponse
    {
        $settings->ai_enabled = $request->boolean('ai_enabled');
        $settings->search_enabled = $request->boolean('search_enabled');
        $settings->assistant_enabled = $request->boolean('assistant_enabled');
        $settings->telegram_ai_enabled = $request->boolean('telegram_ai_enabled');
        $settings->admin_copilot_enabled = $request->boolean('admin_copilot_enabled');
        $settings->chat_model = $request->validated('chat_model');
        $settings->vision_model = $request->validated('vision_model');
        $settings->embedding_model = $request->validated('embedding_model');
        $settings->daily_budget_usd = (float) $request->validated('daily_budget_usd');
        $settings->per_session_rate_limit = (int) $request->validated('per_session_rate_limit');
        $settings->per_conversation_rate_limit = (int) $request->validated('per_conversation_rate_limit');
        $settings->save();

        return back()->with('success', 'تم حفظ إعدادات الذكاء الاصطناعي.');
    }
}
