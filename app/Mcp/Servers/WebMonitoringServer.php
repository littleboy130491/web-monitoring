<?php

namespace App\Mcp\Servers;

use App\Mcp\Resources\StatusOverviewResource;
use App\Mcp\Tools\CreateWebsiteTool;
use App\Mcp\Tools\DeleteWebsiteTool;
use App\Mcp\Tools\GetLatestReportTool;
use App\Mcp\Tools\GetMonitoringHistoryTool;
use App\Mcp\Tools\GetStatusSummaryTool;
use App\Mcp\Tools\GetWebsiteStatusTool;
use App\Mcp\Tools\ListWebsitesTool;
use App\Mcp\Tools\RunMonitoringTool;
use App\Mcp\Tools\UpdateWebsiteTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Web Monitoring')]
#[Version('1.0.0')]
#[Instructions(<<<'MARKDOWN'
    This server exposes a website monitoring application. Each monitored website is checked
    for HTTP status, response time, SSL certificate, domain expiry, content changes and broken
    assets. Results have a status of "up", "down", "error" or "warning".

    - Start with `get-status-summary` for an overview, or `list-websites` to find website IDs.
    - Use `get-website-status` and `get-monitoring-history` to drill into one website.
    - `run-monitoring` queues background checks; results appear a short while later.
    - `create-website`, `update-website` and `delete-website` manage the monitored websites.
    MARKDOWN)]
class WebMonitoringServer extends Server
{
    protected array $tools = [
        GetStatusSummaryTool::class,
        ListWebsitesTool::class,
        GetWebsiteStatusTool::class,
        GetMonitoringHistoryTool::class,
        GetLatestReportTool::class,
        RunMonitoringTool::class,
        CreateWebsiteTool::class,
        UpdateWebsiteTool::class,
        DeleteWebsiteTool::class,
    ];

    protected array $resources = [
        StatusOverviewResource::class,
    ];

    protected array $prompts = [
        //
    ];
}
