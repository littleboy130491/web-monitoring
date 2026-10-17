<?php

namespace App\Mcp\Tools;

use App\Models\MonitoringReport;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-latest-report')]
#[Description('Get the most recent monitoring email reports with their flagged-issue summary (down sites, expiring domains, significant content changes, broken assets) and delivery status.')]
#[IsReadOnly]
class GetLatestReportTool extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'limit' => 'nullable|integer|min:1|max:20',
        ]);

        $reports = MonitoringReport::latest()->latest('id')->limit($validated['limit'] ?? 1)->get();

        return Response::json([
            'reports' => $reports->map(fn (MonitoringReport $report) => [
                'id' => $report->id,
                'subject' => $report->subject,
                'recipient' => $report->recipient,
                'status' => $report->status,
                'error_message' => $report->error_message,
                'triggered_by' => $report->triggered_by,
                'flagged_count' => $report->flaggedCount(),
                'summary' => $report->summary,
                'sent_at' => $report->sent_at?->toIso8601String(),
                'created_at' => $report->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()->min(1)->max(20)->description('Number of recent reports to return (default 1).'),
        ];
    }
}
