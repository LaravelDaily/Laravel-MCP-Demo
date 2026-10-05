<?php

use App\Http\Middleware\ResolveMcpWorkspace;
use App\Mcp\Servers\TeamBoardServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp/workspaces/{workspace}', TeamBoardServer::class)
    ->middleware(['auth:sanctum', ResolveMcpWorkspace::class]);
