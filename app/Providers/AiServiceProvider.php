<?php

namespace App\Providers;

use App\Ai\Admin\AdminProposableActions;
use App\Ai\Corpus\ArabicTextNormalizer;
use App\Ai\KitSafetySettings;
use App\Models\Page;
use App\Observers\PageCorpusObserver;
use Illuminate\Support\ServiceProvider;
use Saad\AiKit\Approvals\Contracts\ActionRegistry;
use Saad\AiKit\Safety\SafetySettings;

/**
 * Wires the AI backbone. Kept separate from AppServiceProvider so all AI
 * plumbing lives in one reviewable place.
 *
 * The "openrouter" driver override (exact usage.cost capture for spend
 * attribution, reasoning-delta re-emission for "thinking" streams, retries,
 * final-step tool withholding) now comes from saad/ai-kit's Gateway module —
 * see Saad\AiKit\Gateway\GatewayServiceProvider and config/ai-kit.php.
 */
class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ArabicTextNormalizer::class);

        // ai-kit's kill switch and budget guard read the operator's
        // AiSettings through this adapter instead of static config.
        $this->app->singleton(SafetySettings::class, KitSafetySettings::class);

        // ai-kit's proposal flow resolves actions from the app's unified
        // AdminActionRegistry (this rebinding beats the kit's default
        // in-memory registry).
        $this->app->singleton(ActionRegistry::class, AdminProposableActions::class);
    }

    /**
     * The corpus-sync observer is registered HERE — not in Page::booted() —
     * so the Page model stays free of AI concerns and the whole integration
     * remains removable from this one provider.
     */
    public function boot(): void
    {
        Page::observe(PageCorpusObserver::class);
    }
}
