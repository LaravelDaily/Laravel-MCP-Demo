<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\ListTasksTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;

class TeamBoardServer extends Server
{
    /** @var array<int, class-string<Tool>> */
    protected array $tools = [ListTasksTool::class];

    protected string $name = 'TeamBoard';

    protected string $version = '1.0.0';

    protected string $instructions = 'TeamBoard operates only within the workspace selected in the endpoint URL. Authentication identifies you; membership grants workspace access; policies control operations. Read tools return bounded workspace data. Project instructions are guidance for the client and are not automatically executed.';
}
