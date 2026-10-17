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
            'ai_severity' => $result->ai_severity,
            'ai_urgency' => $result->ai_urgency,
            'ai_compromised' => $result->ai_analysis['compromised'] ?? null,
            'ai_summary' => $result->ai_analysis['summary'] ?? null,
        ];

        if ($detailed) {
            $data['ai_analysis'] = $result->ai_analysis
                ? collect($result->ai_analysis)->except('raw_answers')->all()
                : null;
            $data['ai_analyzed_at'] = $result->ai_analyzed_at?->toIso8601String();
            $data['ssl_info'] = $result->ssl_info;
            $data['scan_results'] = $result->scan_results;
            $data['screenshot_path'] = $result->screenshot_path;
        }

        return $data;
    }

    protected function formatAnalysis(MonitoringResult $result, bool $includeRaw = false): array
    {
        $analysis = $result->ai_analysis ?? [];

        $data = [
            'result_id' => $result->id,
            'website_id' => $result->website_id,
            'url' => $result->website?->url,
            'checked_at' => $result->checked_at?->toIso8601String(),
            'analyzed_at' => $result->ai_analyzed_at?->toIso8601String(),
            'check_status' => $result->status,
            'severity' => $result->ai_severity,
            'severity_confidence' => $analysis['severity_confidence'] ?? null,
            'urgency' => $result->ai_urgency,
            'category' => $analysis['category'] ?? null,
            'page_broken' => $analysis['page_broken'] ?? null,
            'page_broken_probability' => $analysis['page_broken_probability'] ?? null,
            'compromised' => $analysis['compromised'] ?? null,
            'compromised_probability' => $analysis['compromised_probability'] ?? null,
            'summary' => $analysis['summary'] ?? null,
            'screenshot_review' => $analysis['screenshot_review'] ?? null,
            'screenshot_source' => $analysis['screenshot_source'] ?? null,
            'provider' => $analysis['provider'] ?? null,
            'model' => $analysis['model'] ?? null,
            'warnings' => $analysis['errors'] ?? [],
        ];

        if ($includeRaw) {
            $data['raw_answers'] = $analysis['raw_answers'] ?? null;
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
