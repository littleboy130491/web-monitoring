<?php

namespace Tests\Feature;

use App\Models\MonitoringReport;
use App\Services\MonitoringReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class MonitoringReportServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_retries_transient_failures_before_marking_report_sent(): void
    {
        config([
            'monitoring.report_mail_retry_attempts' => 3,
            'monitoring.report_mail_retry_backoff' => [0, 0],
        ]);

        $report = $this->makeReport();

        $service = new class extends MonitoringReportService
        {
            public int $attempts = 0;

            protected function deliver(MonitoringReport $report): void
            {
                $this->attempts++;

                if ($this->attempts < 3) {
                    throw new RuntimeException('Could not reach the remote Mailgun server.');
                }
            }
        };

        $sentReport = $service->send($report);

        $this->assertSame(3, $service->attempts);
        $this->assertTrue($sentReport->isSent());
        $this->assertNull($sentReport->error_message);
        $this->assertNotNull($sentReport->sent_at);
    }

    public function test_send_records_exception_chain_after_retries_are_exhausted(): void
    {
        config([
            'monitoring.report_mail_retry_attempts' => 2,
            'monitoring.report_mail_retry_backoff' => [0],
        ]);

        $report = $this->makeReport();

        $service = new class extends MonitoringReportService
        {
            public int $attempts = 0;

            protected function deliver(MonitoringReport $report): void
            {
                $this->attempts++;

                throw new RuntimeException(
                    'Could not reach the remote Mailgun server.',
                    0,
                    new LogicException('Temporary DNS lookup failure.')
                );
            }
        };

        $failedReport = $service->send($report);

        $this->assertSame(2, $service->attempts);
        $this->assertTrue($failedReport->hasFailed());
        $this->assertStringContainsString(RuntimeException::class, $failedReport->error_message);
        $this->assertStringContainsString('Could not reach the remote Mailgun server.', $failedReport->error_message);
        $this->assertStringContainsString(LogicException::class, $failedReport->error_message);
        $this->assertStringContainsString('Temporary DNS lookup failure.', $failedReport->error_message);
    }

    private function makeReport(): MonitoringReport
    {
        return MonitoringReport::create([
            'recipient' => 'admin@example.com',
            'subject' => 'Monitoring Report',
            'body_html' => '<p>Report</p>',
            'summary' => [
                'down' => [],
                'expiring' => [],
                'content_changed' => [],
                'broken_assets' => [],
            ],
            'status' => 'pending',
            'triggered_by' => 'command',
        ]);
    }
}
