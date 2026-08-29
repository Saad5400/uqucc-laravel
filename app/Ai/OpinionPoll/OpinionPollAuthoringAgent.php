<?php

namespace App\Ai\OpinionPoll;

use App\Ai\ModelRegistry;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * The tool-driven agent behind opinion-poll generation.
 *
 * Same shape as {@see \App\Ai\Quiz\QuizAuthoringAgent} — the authoring tier,
 * a dedicated class so tests get a stable faking handle
 * (`OpinionPollAuthoringAgent::fake([...])`), and a single
 * {@see SubmitOpinionPollTool} whose validator corrects a rejected poll inside
 * the same conversation.
 *
 * Reasoning effort is dialled down from the quiz's: a poll has no right answer
 * to reason toward and no distractors to balance, so the extra thinking buys
 * nothing but latency and spend on a call that runs every single day.
 */
#[MaxSteps(6)]
class OpinionPollAuthoringAgent implements Agent, HasProviderOptions, HasTools
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
            (string) config('ai.default', 'openrouter') => app(ModelRegistry::class)->authoring(),
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
            'reasoning' => ['effort' => 'low'],
        ];
    }
}
