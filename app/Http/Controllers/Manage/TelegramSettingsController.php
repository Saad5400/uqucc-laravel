<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Http\Requests\Manage\UpdateTelegramSettingsRequest;
use App\Settings\TelegramSettings;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TelegramSettingsController extends Controller
{
    /**
     * Show the settings page (currently a single Telegram settings card).
     */
    public function edit(TelegramSettings $settings): Response
    {
        return Inertia::render('manage/settings/Index', [
            'telegram' => [
                'allowed_chat_ids' => $settings->page_management_allowed_chat_ids,
                'auto_delete_messages' => $settings->page_management_auto_delete_messages,
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
