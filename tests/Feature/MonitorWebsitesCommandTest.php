<?php

namespace Tests\Feature;

use App\Jobs\GenerateMonitoringReportJob;
use App\Models\MonitoringResult;
use App\Models\Website;
use App\Services\WebsiteMonitoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MonitorWebsitesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_report_option_dispatches_report_job_after_monitoring(): void
    {
        Queue::fake();

        $website = Website::create([
            'url' => 'https://example.com',
            'description' => null,
            'is_active' => true,
            'check_interval' => 300,
            'headers' => null,
        ]);

        $this->app->bind(WebsiteMonitoringService::class, fn () => new class extends WebsiteMonitoringService
        {
            public function monitor(Website $website, int $timeout = 30, bool $takeScreenshot = false): MonitoringResult
            {
                return MonitoringResult::create([
                    'website_id' => $website->id,
                    'status_code' => 200,
                    'response_time' => 120,
                    'status' => 'up',
                    'error_message' => null,
                    'ssl_info' => null,
                    'domain_days_until_expiry' => null,
                    'domain_expires_at' => null,
                    'scan_results' => null,
                    'screenshot_path' => null,
                    'checked_at' => now(),
                ]);
            }
        });

        $this->artisan('monitor:websites', ['--queue-report' => true])
            ->expectsOutput('Monitoring 1 websites...')
            ->expectsOutput('Monitoring report queued.')
            ->assertExitCode(0);

        Queue::assertPushed(GenerateMonitoringReportJob::class, function (GenerateMonitoringReportJob $job) {
            return $job->triggeredBy === 'command';
        });
    }
}
