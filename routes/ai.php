<?php

use App\Mcp\Servers\UqccServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP Servers
|--------------------------------------------------------------------------
|
| laravel/mcp auto-loads this file when it exists (see
| McpServiceProvider::registerRoutes), before the web routes, so the /mcp
| endpoint is never shadowed by the catch-all page route. The server is
| public and read-only; the `mcp` limiter is defined in AppServiceProvider.
|
*/

Mcp::web('/mcp', UqccServer::class)
    ->middleware('throttle:mcp');
