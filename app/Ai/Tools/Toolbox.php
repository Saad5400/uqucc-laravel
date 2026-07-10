<?php

namespace App\Ai\Tools;

use Laravel\Ai\Contracts\Tool;

/**
 * The single list of the site's AI capabilities. Each tool is written once
 * (implementing the laravel/ai {@see Tool} contract) and registered in both
 * surfaces from here: the MCP server wraps each class in an adapter for
 * external AI clients, and the Phase-3 in-app assistant will hand the same
 * classes to its agent.
 */
class Toolbox
{
    /**
     * Every public, read-only tool the site exposes.
     *
     * @return list<class-string<Tool>>
     */
    public static function tools(): array
    {
        return [
            SearchContentTool::class,
            GetPageTool::class,
            CalculateGpaTool::class,
            CalculateDeprivationTool::class,
            CalculateTransferTool::class,
            FindTutorsTool::class,
        ];
    }
}
