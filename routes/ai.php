<?php

use App\Mcp\Servers\UqccAdminServer;
use App\Mcp\Servers\UqccServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| MCP Servers
|--------------------------------------------------------------------------
|
| laravel/mcp auto-loads this file when it exists (see
| McpServiceProvider::registerRoutes), before the web routes, so the /mcp
| endpoint is never shadowed by the catch-all page route.
|
| Two servers are exposed:
|   - /mcp        public, read-only, unauthenticated (UqccServer).
|   - /mcp/admin  moderation tools, protected by OAuth2 (UqccAdminServer).
|
| `Mcp::oauthRoutes()` registers the OAuth2 discovery + dynamic client
| registration endpoints an MCP client needs to negotiate access; the admin
| server route is guarded by `auth:api` (Passport). An unauthenticated
| browser hitting the authorization endpoint is redirected to the /manage
| login and back (see bootstrap/app.php `redirectGuestsTo`). Both `mcp` and
| `mcp-admin` limiters are defined in AppServiceProvider.
|
*/

Mcp::oauthRoutes();

Mcp::web('/mcp', UqccServer::class)
    ->middleware('throttle:mcp');

Mcp::web('/mcp/admin', UqccAdminServer::class)
    ->middleware(['auth:api', 'throttle:mcp-admin']);
