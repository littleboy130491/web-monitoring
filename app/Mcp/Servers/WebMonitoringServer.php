<?php

namespace App\Mcp\Servers;

use App\Mcp\Resources\StatusOverviewResource;
use App\Mcp\Tools\AnalyzeWebsiteTool;
use App\Mcp\Tools\CreateWebsiteTool;
use App\Mcp\Tools\DeleteWebsiteTool;
use App\Mcp\Tools\GetAiAnalysisTool;
use App\Mcp\Tools\GetLatestReportTool;
use App\Mcp\Tools\GetMonitoringHistoryTool;
use App\Mcp\Tools\GetStatusSummaryTool;
use App\Mcp\Tools\GetWebsiteStatusTool;
use App\Mcp\Tools\ListAiFindingsTool;
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
    assets, and may carry an AI severity (none/low/medium/high/critical). Results have a status of "up", "down", "error" or "warning".

    - Start with `get-status-summary` for an overview, or `list-websites` to find website IDs.
    - Use `get-website-status` and `get-monitoring-history` to drill into one website.
    - `run-monitoring` queues background checks; results appear a short while later.
    - AI analysis (Jev): `list-ai-findings` shows what the AI flagged across all sites (most severe first),
      `get-ai-analysis` shows the stored analysis for one site or result, and `analyze-website` runs a new one.
      Analyses run automatically after checks, so prefer reading stored ones before running new ones.
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
        ListAiFindingsTool::class,
        GetAiAnalysisTool::class,
        AnalyzeWebsiteTool::class,
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
