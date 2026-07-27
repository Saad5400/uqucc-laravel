<?php

namespace App\Ai\Quiz;

use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * The tool-driven agent behind daily quiz question generation.
 *
 * Like {@see \App\Ai\Authoring\PageAuthoringAgent} it is pinned to the
 * "smart" authoring tier — one scheduled call per day where question quality
 * beats latency — and exists as a dedicated class so tests get a stable
 * faking handle: `QuizAuthoringAgent::fake([...])`.
 *
 * It submits its question through a single {@see SubmitQuizQuestionTool}: the
 * validator lives behind the tool, so a rejected question is corrected inside
 * the same conversation (the model sees exactly what failed) rather than via a
 * blind stateless retry. `MaxSteps` bounds that self-correction loop.
 */
#[MaxSteps(8)]
class QuizAuthoringAgent implements Agent, HasProviderOptions, HasTools
{
    use Promptable;

    /**
     * @param  array<int, Tool>  $agentTools
     */
    public function __construct(
        private readonly string $agentInstructions,
        private readonly array $agentTools = [],
    ) {}

    public function instructions(): Stringable|string
    {
        return $this->agentInstructions;
    }

    /**
     * @return iterable<int, Tool>
     */
    public function tools(): iterable
    {
        return $this->agentTools;
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
