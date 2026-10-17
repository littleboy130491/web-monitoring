<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\FormatsMonitoringData;
use App\Models\MonitoringResult;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-monitoring-history')]
#[Description('Get recent monitoring results, newest first, optionally for a single website, filtered by status or by how many hours back to look. Includes uptime percentage and average response time for the returned window.')]
#[IsReadOnly]
class GetMonitoringHistoryTool extends Tool
{
    use FormatsMonitoringData;

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'website_id' => 'nullable|integer|exists:websites,id',
            'status' => 'nullable|in:up,down,error,warning',
            'hours' => 'nullable|integer|min:1|max:8760',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        $query = MonitoringResult::with('website')->latest('checked_at')->latest('id');

        if (! empty($validated['website_id'])) {
            $query->where('website_id', $validated['website_id']);
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        if (! empty($validated['hours'])) {
            $query->where('checked_at', '>=', now()->subHours($validated['hours']));
        }

        $results = $query->limit($validated['limit'] ?? 20)->get();
        $responseTimes = $results->pluck('response_time')->filter();

        return Response::json([
            'count' => $results->count(),
            'uptime_percent' => $results->isNotEmpty()
                ? round($results->where('status', 'up')->count() / $results->count() * 100, 2)
                : null,
            'average_response_time_ms' => $responseTimes->isNotEmpty() ? (int) round($responseTimes->avg()) : null,
            'results' => $results->map(fn (MonitoringResult $r) => [
                'website_id' => $r->website_id,
                'url' => $r->website?->url,
                ...$this->formatResult($r),
            ])->values(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'website_id' => $schema->integer()->description('Only return results for this website ID.'),
            'status' => $schema->string()->enum(['up', 'down', 'error', 'warning'])->description('Only return results with this status.'),
            'hours' => $schema->integer()->min(1)->max(8760)->description('Only return results checked within this many hours.'),
            'limit' => $schema->integer()->min(1)->max(200)->description('Maximum number of results to return (default 20).'),
        ];
    }
}
