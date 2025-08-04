<?php

namespace App\Console\Commands;

use App\Models\Website;
use App\Services\WebsiteMonitoringService;
use Illuminate\Console\Command;

class MonitorWebsites extends Command
{
    protected $signature = 'monitor:websites 
                            {--id= : Monitor specific website ID}
                            {--screenshot : Take screenshots of websites}
                            {--timeout=30 : Request timeout in seconds}';

    protected $description = 'Monitor websites for status, health, content changes, and take screenshots';

    private WebsiteMonitoringService $monitoringService;

    public function __construct(WebsiteMonitoringService $monitoringService)
    {
        parent::__construct();
        $this->monitoringService = $monitoringService;
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

        foreach ($websites as $website) {
            $result = $this->monitoringService->monitor(
                $website,
                (int) $this->option('timeout'),
                $this->option('screenshot')
            );
            $this->displayResult($website, $result->toArray());
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Website monitoring completed!');

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

        if ($result['content_changed']) {
            $this->line("   <fg=blue>Content Changed!</fg=blue>");
        }

        if ($result['error_message']) {
            $this->line("   Error: {$result['error_message']}");
        }

        if ($result['ssl_info']) {
            $ssl = json_decode($result['ssl_info'], true);
            $this->line("   SSL: Expires in {$ssl['expires_in_days']} days");
        }

        if ($result['screenshot_path']) {
            $this->line("   📸 Screenshot: {$result['screenshot_path']}");
        }
    }
}
