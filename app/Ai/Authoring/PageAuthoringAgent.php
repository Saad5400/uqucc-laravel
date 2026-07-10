<?php

namespace App\Ai\Authoring;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * The tool-less, single-shot agent behind document → page authoring.
 *
 * Each {@see PageAuthor} step (update-or-new decision, new-page draft, page
 * revision) builds its own instruction set and runs exactly ONE text
 * generation through this agent. Unlike the chat-tier copilot it is pinned to
 * the "smart" authoring tier — config('ai.authoring'): a stronger reasoning
 * model with full reasoning effort and a long timeout, because these are
 * rare, admin-triggered, review-gated calls where quality beats latency.
 *
 * A dedicated class (rather than AnonymousAgent) both carries the tier wiring
 * (provider()/providerOptions(), like {@see \App\Ai\Agents\StudentAssistant})
 * and gives tests a stable faking handle: `PageAuthoringAgent::fake([...])`.
 */
class PageAuthoringAgent implements Agent, HasProviderOptions
{
    use Promptable;

    public function __construct(private readonly string $agentInstructions) {}

    public function instructions(): Stringable|string
    {
        return $this->agentInstructions;
    }

    /**
     * The configured default provider running the authoring-tier model.
     *
     * @return array<string, string>
     */
    public function provider(): array
    {
        return [
            (string) config('ai.default', 'openrouter') => (string) config('ai.authoring.model', 'deepseek/deepseek-v4-pro'),
        ];
    }

    public function timeout(): int
    {
        return (int) config('ai.authoring.timeout', 180);
    }

    /**
     * OpenRouter-specific options: full reasoning effort — drafting a whole
     * page from a regulation document is exactly the workload that benefits.
     * Other providers get no extra options.
     *
     * @return array<string, mixed>
     */
    public function providerOptions(Lab|string $provider): array
    {
        if ($provider !== Lab::OpenRouter && $provider !== Lab::OpenRouter->value) {
            return [];
        }

        return [
            'reasoning' => ['effort' => (string) config('ai.authoring.reasoning_effort', 'high')],
        ];
    }
}
