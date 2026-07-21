<?php

namespace App\Ai\Quiz;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * The tool-less, single-shot agent behind daily quiz question generation.
 *
 * Like {@see \App\Ai\Authoring\PageAuthoringAgent} it is pinned to the
 * "smart" authoring tier — one scheduled call per day where question quality
 * beats latency — and exists as a dedicated class so tests get a stable
 * faking handle: `QuizAuthoringAgent::fake([...])`.
 */
class QuizAuthoringAgent implements Agent, HasProviderOptions
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
