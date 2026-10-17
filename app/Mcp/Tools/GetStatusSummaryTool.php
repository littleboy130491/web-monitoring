<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\FormatsMonitoringData;
use App\Models\Website;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-status-summary')]
#[Description('Get an overall health summary: counts of websites by latest status, average response time, and websites that need attention (down/error/warning, domain or SSL expiring within 14 days, broken assets, AI-flagged high/critical severity or possible compromise).')]
#[IsReadOnly]
class GetStatusSummaryTool extends Tool
{
    use FormatsMonitoringData;

    public function handle(Request $request): Response
    {
        return Response::json($this->summary());
    }

    public function summary(): array
    {
        $websites = Website::with('latestResult')->get();
        $active = $websites->where('is_active', true);

        $counts = ['up' => 0, 'down' => 0, 'error' => 0, 'warning' => 0, 'never_checked' => 0];
        $attention = [];

        foreach ($active as $website) {
            $result = $website->latestResult;

            if (! $result) {
                $counts['never_checked']++;

                continue;
            }

            $counts[$result->status] = ($counts[$result->status] ?? 0) + 1;

            $reasons = [];

            if ($result->status !== 'up') {
                $reasons[] = "status is {$result->status}".($result->error_message ? ": {$result->error_message}" : '');
            }

            if ($result->domain_days_until_expiry !== null && $result->domain_days_until_expiry <= 14) {
                $reasons[] = "domain expires in {$result->domain_days_until_expiry} days";
            }

            $sslDays = $result->ssl_info['expires_in_days'] ?? null;
            if ($sslDays !== null && $sslDays <= 14) {
                $reasons[] = "SSL certificate expires in {$sslDays} days";
            }

            $brokenAssets = count($result->scan_results['broken_assets'] ?? []);
            if ($brokenAssets > 0) {
                $reasons[] = "{$brokenAssets} broken asset(s)";
            }

            if ($result->ai_analysis['compromised'] ?? false) {
                $reasons[] = 'AI flagged a possible compromise';
            } elseif (in_array($result->ai_severity, ['high', 'critical'], true)) {
                $reasons[] = "AI severity {$result->ai_severity}";
            }

            if ($reasons) {
                $attention[] = [
                    'id' => $website->id,
                    'url' => $website->url,
                    'reasons' => $reasons,
                    'checked_at' => $result->checked_at?->toIso8601String(),
                ];
            }
        }

        $responseTimes = $active->pluck('latestResult.response_time')->filter();

        return [
            'total_websites' => $websites->count(),
            'active_websites' => $active->count(),
            'status_counts' => $counts,
            'average_response_time_ms' => $responseTimes->isNotEmpty() ? (int) round($responseTimes->avg()) : null,
            'last_checked_at' => $active->pluck('latestResult.checked_at')->filter()->max()?->toIso8601String(),
            'needs_attention' => $attention,
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
