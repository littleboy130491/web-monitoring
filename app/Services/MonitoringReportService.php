<?php

namespace App\Services;

use App\Mail\MonitoringReportMail;
use App\Models\MonitoringReport;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class MonitoringReportService
{
    /**
     * Build the summary array from a collection of MonitoringResults,
     * keeping only flagged entries: down, expiring ≤7d, significant content
     * change, and broken assets.
     */
    public function buildSummary(Collection $results): array
    {
        $down = [];
        $expiring = [];
        $contentChanged = [];
        $brokenAssets = [];

        foreach ($results as $result) {
            // Sites that are down or errored
            if (in_array($result->status, ['down', 'error'])) {
                $down[] = [
                    'url' => $result->website->url,
                    'status_code' => $result->status_code,
                    'error' => $result->error_message,
                ];
            }

            // Domain expiring in ≤7 days (or already expired)
            if ($result->domain_days_until_expiry !== null && $result->domain_days_until_expiry <= 7) {
                $expiring[] = [
                    'url' => $result->website->url,
                    'expires_at' => $result->domain_expires_at?->format('Y-m-d'),
                    'days' => $result->domain_days_until_expiry,
                ];
            }

            // Significant content change (any page > 10%)
            $scan = is_array($result->scan_results)
                ? $result->scan_results
                : (json_decode((string) ($result->scan_results ?? '[]'), true) ?: []);

            $hasSignificantChange = (bool) ($scan['any_significant_change'] ?? $result->content_changed ?? false);
            if ($hasSignificantChange) {
                $pages = collect($scan['pages'] ?? []);
                $changedPages = $pages
                    ->where('significant', true)
                    ->map(fn ($p) => ['slug' => $p['slug'], 'change_percent' => $p['change_percent']])
                    ->values()
                    ->all();

                if (empty($changedPages) && $pages->isNotEmpty()) {
                    $topPage = $pages
                        ->sortByDesc(fn ($p) => (float) ($p['change_percent'] ?? 0))
                        ->first();

                    if ($topPage) {
                        $changedPages[] = [
                            'slug' => $topPage['slug'] ?? 'home',
                            'change_percent' => $topPage['change_percent'] ?? 0,
                        ];
                    }
                }

                if ($changedPages) {
                    $contentChanged[] = [
                        'url' => $result->website->url,
                        'pages' => $changedPages,
                    ];
                }
            }

            // Broken assets (404 CSS/JS)
            $assets = $scan['broken_assets'] ?? [];
            $hasBrokenAssets = ! empty($assets) || ! empty($scan['has_broken_assets']);
            if ($hasBrokenAssets) {
                if (empty($assets)) {
                    $assets = [[
                        'type' => 'unknown',
                        'url' => '(detected, details unavailable)',
                    ]];
                }

                $brokenAssets[] = [
                    'url' => $result->website->url,
                    'assets' => $assets,
                ];
            }
        }

        return [
            'down' => $down,
            'expiring' => $expiring,
            'content_changed' => $contentChanged,
            'broken_assets' => $brokenAssets,
        ];
    }

    /**
     * Build the email subject line from the summary.
     */
    public function buildSubject(array $summary): string
    {
        $parts = [];
        if (count($summary['down'])) {
            $parts[] = count($summary['down']).' down';
        }
        if (count($summary['expiring'])) {
            $parts[] = count($summary['expiring']).' expiring';
        }
        if (count($summary['content_changed'] ?? $summary['contentChanged'] ?? [])) {
            $parts[] = count($summary['content_changed'] ?? $summary['contentChanged'] ?? []).' changed';
        }
        if (count($summary['broken_assets'] ?? $summary['brokenAssets'] ?? [])) {
            $parts[] = count($summary['broken_assets'] ?? $summary['brokenAssets'] ?? []).' broken assets';
        }

        $issues = $parts ? '['.implode(', ', $parts).']' : '[All Clear]';

        return "Monitoring Report {$issues} – ".now()->format('Y-m-d H:i');
    }

    /**
     * Generate, save, and send a report from a collection of monitoring results.
     * Returns null if no recipient is configured.
     */
    public function generateAndSend(Collection $results, string $triggeredBy = 'command'): ?MonitoringReport
    {
        $recipient = config('monitoring.report_recipient');
        if (! $recipient) {
            Log::warning('MonitoringReportService: REPORT_RECIPIENT_EMAIL is not set, skipping report.');

            return null;
        }

        $summary = $this->buildSummary($results);

        // Create the report record (status: pending)
        $report = MonitoringReport::create([
            'recipient' => $recipient,
            'subject' => $this->buildSubject($summary),
            'body_html' => '',         // filled after rendering
            'summary' => $summary,
            'status' => 'pending',
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
        $maxAttempts = max(1, (int) config('monitoring.report_mail_retry_attempts', 3));
        $backoff = config('monitoring.report_mail_retry_backoff', [5, 15]);
        $lastException = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $this->deliver($report);

                $report->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                    'error_message' => null,
                ]);

                if ($attempt > 1) {
                    Log::info("MonitoringReportService: sent report #{$report->id} after {$attempt} attempts.");
                }

                return $report->fresh();
            } catch (Throwable $e) {
                $lastException = $e;

                Log::warning("MonitoringReportService: failed sending report #{$report->id} on attempt {$attempt}/{$maxAttempts}.", [
                    'report_id' => $report->id,
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'exception_chain' => $this->exceptionChain($e),
                ]);

                if ($attempt < $maxAttempts) {
                    sleep((int) ($backoff[$attempt - 1] ?? end($backoff) ?: 0));
                }
            }
        }

        $errorMessage = $lastException
            ? $this->exceptionSummary($lastException)
            : 'Unknown mail delivery failure.';

        Log::error("MonitoringReportService: failed to send report #{$report->id} after {$maxAttempts} attempts.", [
            'report_id' => $report->id,
            'exception_chain' => $lastException ? $this->exceptionChain($lastException) : [],
        ]);

        $report->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
        ]);

        return $report->fresh();
    }

    protected function deliver(MonitoringReport $report): void
    {
        Mail::to($report->recipient)->send(new MonitoringReportMail($report));
    }

    private function exceptionSummary(Throwable $exception): string
    {
        return collect($this->exceptionChain($exception))
            ->map(fn (array $item) => "{$item['class']}: {$item['message']}")
            ->implode(' | Previous: ');
    }

    private function exceptionChain(Throwable $exception): array
    {
        $chain = [];

        do {
            $chain[] = [
                'class' => $exception::class,
                'message' => $exception->getMessage(),
            ];
        } while ($exception = $exception->getPrevious());

        return $chain;
    }
}
