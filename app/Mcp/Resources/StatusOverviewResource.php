<?php

namespace App\Mcp\Resources;

use App\Mcp\Tools\GetStatusSummaryTool;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Description('Current health overview of all monitored websites.')]
#[Uri('monitoring://status/overview')]
#[MimeType('application/json')]
class StatusOverviewResource extends Resource
{
    public function handle(Request $request, GetStatusSummaryTool $summary): Response
    {
        return Response::json($summary->summary());
    }
}
