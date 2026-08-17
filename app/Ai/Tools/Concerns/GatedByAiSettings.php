<?php

namespace App\Ai\Tools\Concerns;

use Saad\AiKit\Safety\KillSwitch;

/**
 * Shared gating for every AI tool: while the global kill switch is engaged
 * (the operator's master `ai_enabled` toggle or ai-kit's cache switch),
 * tools answer with a bilingual refusal instead of running.
 * Feature-specific toggles (e.g. `search_enabled`) are checked by the tools
 * that need them on top of this.
 */
trait GatedByAiSettings
{
    protected function aiToolsAreDisabled(): bool
    {
        return app(KillSwitch::class)->engaged();
    }

    protected function aiDisabledReply(): string
    {
        return 'أدوات الذكاء الاصطناعي معطلة حالياً من إدارة الموقع. AI tools are currently disabled by the site administration.';
    }
}
