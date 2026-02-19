<?php

namespace App\Services;

use App\Mail\MonitoringReportMail;
use App\Models\MonitoringReport;
use App\Models\MonitoringResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class MonitoringReportService
{
    /**
     * Build the summary array from a collection of MonitoringResults,
     * keeping only flagged entries: down, expiring ≤7d, significant content
     * change, and broken assets.
     */
    public function buildSummary(Collection $results): array
    {
        $down           = [];
        $expiring       = [];
        $contentChanged = [];
        $brokenAssets   = [];

        foreach ($results as $result) {
            // Sites that are down or errored
            if (in_array($result->status, ['down', 'error'])) {
                $down[] = [
                    'url'         => $result->website->url,
                    'status_code' => $result->status_code,
                    'error'       => $result->error_message,
                ];
            }

            // Domain expiring in ≤7 days (or already expired)
            if ($result->domain_days_until_expiry !== null && $result->domain_days_until_expiry <= 7) {
                $expiring[] = [
                    'url'        => $result->website->url,
                    'expires_at' => $result->domain_expires_at?->format('Y-m-d'),
                    'days'       => $result->domain_days_until_expiry,
                ];
            }

            // Significant content change (any page > 10%)
            $scan = $result->scan_results;
            if (!empty($scan['any_significant_change'])) {
                $changedPages = collect($scan['pages'] ?? [])
                    ->where('significant', true)
                    ->map(fn($p) => ['slug' => $p['slug'], 'change_percent' => $p['change_percent']])
                    ->values()
                    ->all();

                if ($changedPages) {
                    $contentChanged[] = [
                        'url'   => $result->website->url,
                        'pages' => $changedPages,
                    ];
                }
            }

            // Broken assets (404 CSS/JS)
            if (!empty($scan['broken_assets'])) {
                $brokenAssets[] = [
                    'url'    => $result->website->url,
                    'assets' => $scan['broken_assets'],
                ];
            }
        }

        return [
            'down'            => $down,
            'expiring'        => $expiring,
            'content_changed' => $contentChanged,
            'broken_assets'   => $brokenAssets,
        ];
    }

    /**
     * Build the email subject line from the summary.
     */
    public function buildSubject(array $summary): string
    {
        $parts = [];
        if (count($summary['down']))           $parts[] = count($summary['down']) . ' down';
        if (count($summary['expiring']))       $parts[] = count($summary['expiring']) . ' expiring';
        if (count($summary['contentChanged'])) $parts[] = count($summary['contentChanged']) . ' changed';
        if (count($summary['brokenAssets']))   $parts[] = count($summary['brokenAssets']) . ' broken assets';

        $issues = $parts ? '[' . implode(', ', $parts) . ']' : '[All Clear]';
        return "Monitoring Report {$issues} – " . now()->format('Y-m-d H:i');
    }

    /**
     * Generate, save, and send a report from a collection of monitoring results.
     * Returns null if no recipient is configured.
     */
    public function generateAndSend(Collection $results, string $triggeredBy = 'command'): ?MonitoringReport
    {
        $recipient = config('monitoring.report_recipient');
        if (!$recipient) {
            Log::warning('MonitoringReportService: REPORT_RECIPIENT_EMAIL is not set, skipping report.');
            return null;
        }

        $summary = $this->buildSummary($results);

        // Create the report record (status: pending)
        $report = MonitoringReport::create([
            'recipient'    => $recipient,
            'subject'      => $this->buildSubject($summary),
            'body_html'    => '',         // filled after rendering
            'summary'      => $summary,
            'status'       => 'pending',
            'triggered_by' => $triggeredBy,
        ]);

        // Render HTML and persist it
        $html = view('emails.monitoring-report', ['report' => $report])->render();
        $report->update(['body_html' => $html]);

        return $this->send($report);
    }

    /**
     * (Re)send an existing MonitoringReport via email.
     * Updates status and sent_at on the record.
     */
    public function send(MonitoringReport $report): MonitoringReport
    {
        try {
            Mail::to($report->recipient)->send(new MonitoringReportMail($report));

            $report->update([
                'status'        => 'sent',
                'sent_at'       => now(),
                'error_message' => null,
            ]);
        } catch (\Exception $e) {
            Log::error("MonitoringReportService: failed to send report #{$report->id}: " . $e->getMessage());

            $report->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }

        return $report->fresh();
    }
}
