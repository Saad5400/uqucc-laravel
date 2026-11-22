<?php

namespace App\Ai;

use App\Settings\AiSettings;
use Saad\AiKit\Safety\SafetySettings;

/**
 * Backs ai-kit's safety layer (kill switch, budget guard) with the operator's
 * {@see AiSettings}: the master `ai_enabled` toggle is the global switch, the
 * per-feature toggles gate their surfaces, and `daily_budget_usd` is the daily
 * budget — where zero or less reads as "already exhausted", keeping the
 * setting's role as an operator kill switch for all paid features.
 *
 * Only the features AiSettings actually declares are consulted; an unknown
 * feature name falls back to the master switch alone rather than AiSettings'
 * deny-by-default match arm, because ai-kit treats undeclared features as
 * enabled and a kit-side scope name must not silently kill a surface.
 *
 * This adapter is a singleton, and so are the kit's KillSwitch and BudgetGuard
 * that hold it, so it never keeps an AiSettings instance of its own: it reads
 * the current one on every call. AiSettings is a scoped binding, renewed for
 * every Octane request and every queued job; a captured instance would keep
 * whatever toggles and budget it first loaded for the whole life of the worker,
 * and an operator's kill switch would stop reaching it.
 */
class KitSafetySettings implements SafetySettings
{
    /** @var list<string> */
    private const FEATURES = ['search', 'assistant', 'telegram', 'admin_copilot', 'admin_assistant'];

    public function enabled(?string $feature = null): bool
    {
        if ($feature === null || ! in_array($feature, self::FEATURES, true)) {
            return $this->settings()->ai_enabled;
        }

        return $this->settings()->isFeatureEnabled($feature);
    }

    public function dailyBudgetUsd(): ?float
    {
        return (float) $this->settings()->daily_budget_usd;
    }

    private function settings(): AiSettings
    {
        return app(AiSettings::class);
    }
}
