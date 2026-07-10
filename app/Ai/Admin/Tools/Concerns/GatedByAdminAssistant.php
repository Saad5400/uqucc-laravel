<?php

namespace App\Ai\Admin\Tools\Concerns;

use App\Settings\AiSettings;

/**
 * Shared gating for the admin assistant's tools: while the master
 * `ai_enabled` switch or the `admin_assistant_enabled` feature toggle is off,
 * the tools refuse instead of running. The HTTP endpoints gate first, so this
 * is defence in depth for any other surface that might hand these tools to a
 * model.
 */
trait GatedByAdminAssistant
{
    protected function adminAssistantIsDisabled(): bool
    {
        return ! app(AiSettings::class)->isFeatureEnabled('admin_assistant');
    }

    protected function adminAssistantDisabledReply(): string
    {
        return 'المساعد الإداري معطل حالياً من إعدادات الذكاء الاصطناعي.';
    }
}
