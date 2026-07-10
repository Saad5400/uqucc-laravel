<?php

namespace App\Ai\Tools\Concerns;

use App\Settings\AiSettings;

/**
 * Shared gating for every AI tool: while the master `ai_enabled` kill switch
 * in {@see AiSettings} is off, tools answer with a bilingual refusal instead
 * of running. Feature-specific toggles (e.g. `search_enabled`) are checked
 * by the tools that need them on top of this.
 */
trait GatedByAiSettings
{
    protected function aiToolsAreDisabled(): bool
    {
        return ! app(AiSettings::class)->ai_enabled;
    }

    protected function aiDisabledReply(): string
    {
        return 'أدوات الذكاء الاصطناعي معطلة حالياً من إدارة الموقع. AI tools are currently disabled by the site administration.';
    }
}
