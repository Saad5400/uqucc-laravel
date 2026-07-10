<?php

namespace App\Ai\Copilot;

use Laravel\Ai\AnonymousAgent;

/**
 * The tool-less, single-shot agent behind the admin page copilot.
 *
 * Each {@see PageCopilot} helper (improve, draft, SEO meta) builds its own
 * instruction set and prompt and runs exactly ONE text generation through
 * this agent — no tools, no conversation history. A dedicated class (rather
 * than AnonymousAgent directly) gives tests a stable faking handle:
 * `PageCopilotAgent::fake([...])`.
 */
class PageCopilotAgent extends AnonymousAgent
{
    public function __construct(string $instructions)
    {
        parent::__construct(instructions: $instructions, messages: [], tools: []);
    }
}
