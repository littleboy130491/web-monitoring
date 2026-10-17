<?php

use App\Http\Middleware\AuthenticateMcpToken;
use App\Mcp\Servers\WebMonitoringServer;
use Laravel\Mcp\Facades\Mcp;

// Local (stdio) server: php artisan mcp:start web-monitoring
Mcp::local('web-monitoring', WebMonitoringServer::class);

// Remote (HTTP) server at /mcp, protected by the MCP_TOKEN bearer token.
Mcp::web('/mcp', WebMonitoringServer::class)
    ->middleware(AuthenticateMcpToken::class);
