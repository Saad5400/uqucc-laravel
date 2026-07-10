<?php

namespace App\Mcp\Tools;

use Illuminate\Container\Container;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool as AiTool;
use Laravel\Ai\Tools\Request as AiRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

/**
 * Exposes one of the site's read-only {@see AiTool} classes as an MCP tool,
 * so each capability is written once and served on both surfaces. The two
 * tool contracts share the JsonSchema builder and a near-identical request
 * API, so the adapter only translates the envelopes — the wrapped tool keeps
 * owning name-worthy description, schema, validation, gating and logic.
 *
 * The MCP name is the snake_case class basename without the `Tool` suffix
 * (e.g. `App\Ai\Tools\SearchContentTool` -> "search_content").
 */
#[IsReadOnly]
class ReadOnlyToolAdapter extends Tool
{
    /**
     * Used for description/schema metadata only; handle() always builds a
     * fresh wrapped instance per call.
     */
    protected AiTool $metadataInstance;

    /**
     * @param  class-string<AiTool>  $toolClass
     */
    public function __construct(protected string $toolClass)
    {
        $this->metadataInstance = $this->newWrapped();

        $basename = Str::chopEnd(class_basename($toolClass), 'Tool');

        $this->name = Str::snake($basename);
        $this->title = Str::headline($basename);
        $this->description = (string) $this->metadataInstance->description();
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->metadataInstance->schema($schema);
    }

    public function handle(Request $request): Response
    {
        $result = (string) $this->newWrapped()->handle(new AiRequest($request->all()));

        return Response::text($result);
    }

    protected function newWrapped(): AiTool
    {
        return Container::getInstance()->make($this->toolClass);
    }
}
