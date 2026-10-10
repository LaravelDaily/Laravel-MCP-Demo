<?php

use App\Http\Middleware\ResolveMcpWorkspace;
use App\Mcp\Servers\TeamBoardServer;
use Laravel\Mcp\Facades\Mcp;

/*
 * OAuth discovery metadata (/.well-known/...) and dynamic client
 * registration (/oauth/register). Passport serves /oauth/authorize
 * and /oauth/token.
 */
Mcp::oauthRoutes();

/*
 * Authenticate the Passport access token first, then apply the per-user MCP
 * limiter to every JSON-RPC request before the workspace in the URL is resolved.
 */
Mcp::web('/mcp/workspaces/{workspace}', TeamBoardServer::class)
    ->middleware(['auth:api', 'throttle:mcp', ResolveMcpWorkspace::class]);
