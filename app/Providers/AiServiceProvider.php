<?php

namespace App\Providers;

use App\Ai\Corpus\ArabicTextNormalizer;
use App\Ai\Gateway\ReasoningOpenRouterGateway;
use App\Models\Page;
use App\Observers\PageCorpusObserver;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Ai;
use Laravel\Ai\Providers\OpenRouterProvider;

/**
 * Wires the AI backbone. Kept separate from AppServiceProvider so all AI
 * plumbing lives in one reviewable place.
 *
 * Overrides the built-in "openrouter" driver with the stock OpenRouterProvider
 * carrying our {@see ReasoningOpenRouterGateway}, which captures OpenRouter's
 * exact usage.cost (for spend attribution) and re-emits reasoning deltas (for
 * "thinking" streams). Custom creators registered via Ai::extend take
 * precedence over the package's built-in driver of the same name.
 */
class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Ai::extend('openrouter', fn (Application $app, array $config): OpenRouterProvider => (new OpenRouterProvider(
            $config,
            $app->make(EventDispatcher::class),
        ))->useTextGateway(new ReasoningOpenRouterGateway($app->make(EventDispatcher::class))));

        $this->app->singleton(ArabicTextNormalizer::class);
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
