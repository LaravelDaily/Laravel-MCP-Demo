<?php

use App\Http\Middleware\ResolveMcpWorkspace;
use App\Mcp\Servers\TeamBoardServer;
use Laravel\Mcp\Facades\Mcp;

/*
 * Authenticate first, then apply the per-user MCP limiter to every JSON-RPC
 * request before the workspace in the URL is resolved.
 */
Mcp::web('/mcp/workspaces/{workspace}', TeamBoardServer::class)
    ->middleware(['auth:sanctum', 'throttle:mcp', ResolveMcpWorkspace::class]);
