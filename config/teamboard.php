<?php

return [

    /*
    |--------------------------------------------------------------------------
    | MCP Rate Limit
    |--------------------------------------------------------------------------
    |
    | The number of MCP JSON-RPC requests each authenticated user may send per
    | minute, shared across every workspace endpoint. Lower it locally to
    | demonstrate the HTTP 429 response.
    |
    */

    'mcp_rate_limit_per_minute' => (int) env('TEAMBOARD_MCP_RATE_LIMIT', 60),

];
