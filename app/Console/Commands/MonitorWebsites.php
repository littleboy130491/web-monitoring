<?php

namespace App\Console\Commands;

use App\Models\Website;
use App\Services\MonitoringReportService;
use App\Services\WebsiteMonitoringService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class MonitorWebsites extends Command
{
    protected $signature = 'monitor:websites 
                            {--id= : Monitor specific website ID}
                            {--screenshot : Take screenshots of websites}
                            {--timeout=30 : Request timeout in seconds}';

    protected $description = 'Monitor websites for status, health, content changes, and take screenshots';

    public function __construct(
        private WebsiteMonitoringService $monitoringService,
        private MonitoringReportService $reportService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Starting website monitoring...');

        $websites = $this->getWebsitesToMonitor();

        if ($websites->isEmpty()) {
            $this->warn('No active websites found to monitor.');
            return Command::SUCCESS;
        }

        $this->info("Monitoring {$websites->count()} websites...");

        $bar = $this->output->createProgressBar($websites->count());
        $bar->start();

        $results = new Collection();

        foreach ($websites as $website) {
            $result = $this->monitoringService->monitor(
                $website,
                (int) $this->option('timeout'),
                $this->option('screenshot')
            );
            $results->push($result);
            $this->displayResult($website, $result->toArray());
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Website monitoring completed!');

        // Generate and send email report
        $this->info('Sending monitoring report...');
        $report = $this->reportService->generateAndSend($results, 'command');

        if ($report === null) {
            $this->warn('Report skipped: REPORT_RECIPIENT_EMAIL is not configured.');
        } elseif ($report->isSent()) {
            $this->info("Report sent to {$report->recipient}.");
        } else {
            $this->error("Report failed to send: {$report->error_message}");
        }

        return Command::SUCCESS;
    }

    private function getWebsitesToMonitor()
    {
        if ($websiteId = $this->option('id')) {
            return Website::where('id', $websiteId)->where('is_active', true)->get();
        }

        return Website::where('is_active', true)->get();
    }


    private function displayResult(Website $website, array $result): void
    {
        $status = match ($result['status']) {
            'up' => '<fg=green>UP</fg=green>',
            'down' => '<fg=red>DOWN</fg=red>',
            'error' => '<fg=red>ERROR</fg=red>',
            'warning' => '<fg=yellow>WARNING</fg=yellow>',
            default => '<fg=gray>UNKNOWN</fg=gray>',
        };

        $this->newLine();
        $this->line("🌐 {$website->url}");
        $this->line("   Status: {$status}");

        if ($result['status_code']) {
            $this->line("   HTTP: {$result['status_code']}");
        }

        if ($result['response_time']) {
            $this->line("   Response Time: {$result['response_time']}ms");
        }

        if ($result['error_message']) {
            $this->line("   <fg=red>Error: {$result['error_message']}</fg=red>");
        }

        if ($result['ssl_info']) {
            $ssl = is_array($result['ssl_info'])
                ? $result['ssl_info']
                : json_decode($result['ssl_info'], true);
            $this->line("   SSL: Expires in {$ssl['expires_in_days']} days");
        }

        if (!empty($result['domain_days_until_expiry'])) {
            $days = $result['domain_days_until_expiry'];
            $date = $result['domain_expires_at'] ?? '?';
            if ($days <= 0) {
                $this->line("   <fg=red>Domain EXPIRED ({$date})</fg=red>");
            } elseif ($days <= 7) {
                $this->line("   <fg=red>Domain expires in {$days}d ({$date})</fg=red>");
            } else {
                $this->line("   Domain: expires in {$days}d ({$date})");
            }
        }

        // Deep scan results
        $scan = $result['scan_results'] ?? null;
        if ($scan) {
            $scan = is_array($scan) ? $scan : json_decode($scan, true);
        }

        if (!empty($scan['pages'])) {
            foreach ($scan['pages'] as $page) {
                $pct = $page['change_percent'];
                $slug = $page['slug'];
                $first = !$page['previous_file_found'];

                if ($first) {
                    $this->line("   Scan [{$slug}]: first snapshot saved");
                } elseif ($page['significant']) {
                    $this->line("   <fg=yellow>Scan [{$slug}]: {$pct}% changed (significant)</fg=yellow>");
                } else {
                    $this->line("   Scan [{$slug}]: {$pct}% changed");
                }
            }
        }

        if (!empty($scan['broken_assets'])) {
            $count = count($scan['broken_assets']);
            $this->line("   <fg=red>Broken assets: {$count} file(s) returning 404</fg=red>");
            foreach ($scan['broken_assets'] as $asset) {
                $this->line("     - [{$asset['type']}] {$asset['url']}");
            }
        }

        if ($result['screenshot_path']) {
            $this->line("   📸 Screenshot: {$result['screenshot_path']}");
        }
    }
}
