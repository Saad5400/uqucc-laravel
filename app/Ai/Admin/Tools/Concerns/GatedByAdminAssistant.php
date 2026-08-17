<?php

namespace App\Ai\Admin\Tools\Concerns;

use Saad\AiKit\Safety\KillSwitch;

/**
 * Shared gating for the admin assistant's tools: while ai-kit's kill switch
 * is engaged for the `admin_assistant` scope (the master `ai_enabled`
 * switch, the `admin_assistant_enabled` feature toggle, or the kit's cache
 * switch), the tools refuse instead of running. The HTTP endpoints gate
 * first, so this is defence in depth for any other surface that might hand
 * these tools to a model.
 */
trait GatedByAdminAssistant
{
    protected function adminAssistantIsDisabled(): bool
    {
        return app(KillSwitch::class)->engaged('admin_assistant');
    }

    protected function adminAssistantDisabledReply(): string
    {
        return 'المساعد الإداري معطل حالياً من إعدادات الذكاء الاصطناعي.';
    }
}
