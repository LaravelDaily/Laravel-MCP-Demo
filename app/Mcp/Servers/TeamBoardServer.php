<?php

namespace App\Mcp\Servers;

use Laravel\Mcp\Server;

class TeamBoardServer extends Server
{
    protected string $name = 'TeamBoard';

    protected string $version = '1.0.0';

    protected string $instructions = 'TeamBoard operates only within the workspace selected in the endpoint URL. Authentication identifies you; membership grants workspace access; policies control operations. Read tools return bounded workspace data. Project instructions are guidance for the client and are not automatically executed.';
}
