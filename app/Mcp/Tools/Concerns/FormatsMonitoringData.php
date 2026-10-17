<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\MonitoringResult;
use App\Models\Website;

trait FormatsMonitoringData
{
    protected function formatWebsite(Website $website): array
    {
        return [
            'id' => $website->id,
            'url' => $website->url,
            'description' => $website->description,
            'is_active' => $website->is_active,
            'check_interval' => $website->check_interval,
            'headers' => $website->headers,
            'latest_result' => $website->latestResult
                ? $this->formatResult($website->latestResult)
                : null,
        ];
    }

    protected function formatResult(MonitoringResult $result, bool $detailed = false): array
    {
        $data = [
            'id' => $result->id,
            'status' => $result->status,
            'status_code' => $result->status_code,
            'response_time_ms' => $result->response_time,
            'error_message' => $result->error_message,
            'content_changed' => $result->content_changed,
            'ssl_expires_in_days' => $result->ssl_info['expires_in_days'] ?? null,
            'domain_expires_at' => $result->domain_expires_at?->toDateString(),
            'domain_days_until_expiry' => $result->domain_days_until_expiry,
            'broken_assets_count' => count($result->scan_results['broken_assets'] ?? []),
            'checked_at' => $result->checked_at?->toIso8601String(),
        ];

        if ($detailed) {
            $data['ssl_info'] = $result->ssl_info;
            $data['scan_results'] = $result->scan_results;
            $data['screenshot_path'] = $result->screenshot_path;
        }

        return $data;
    }

    protected function findWebsite(int|string|null $id, ?string $url = null): ?Website
    {
        if ($id !== null && $id !== '') {
            return Website::find($id);
        }

        if ($url !== null && $url !== '') {
            return Website::where('url', $url)->first()
                ?? Website::where('url', rtrim($url, '/'))->orWhere('url', rtrim($url, '/').'/')->first();
        }

        return null;
    }
}
