<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Gives a Toolbox tool its canonical short name on the agent surface.
 *
 * laravel/ai names a tool by its class basename unless it exposes a `name()`
 * method, so the raw classes would surface as "SearchContentTool" etc. This
 * decorator applies the same convention the MCP surface uses
 * ({@see \App\Mcp\Tools\ReadOnlyToolAdapter}): the snake_case basename
 * without the `Tool` suffix — SearchContentTool -> "search_content" — so both
 * surfaces, the system prompt, and the citation extractor agree on names.
 * Everything else delegates to the wrapped tool.
 */
class NamedTool implements Tool
{
    public function __construct(private readonly Tool $tool) {}

    public function name(): string
    {
        return Str::snake(Str::chopEnd(class_basename($this->tool), 'Tool'));
    }

    public function description(): Stringable|string
    {
        return $this->tool->description();
    }

    public function handle(Request $request): Stringable|string
    {
        return $this->tool->handle($request);
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->tool->schema($schema);
    }
}
