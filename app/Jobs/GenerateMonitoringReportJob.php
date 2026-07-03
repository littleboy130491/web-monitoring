<?php

namespace App\Jobs;

use App\Models\MonitoringResult;
use App\Services\MonitoringReportService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateMonitoringReportJob implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public $timeout = 120;

    public function backoff(): array
    {
        return [30, 120];
    }

    public function __construct(
        public Carbon $startedAt,
        public string $triggeredBy = 'manual'
    ) {}

    public function handle(MonitoringReportService $reportService): void
    {
        $results = MonitoringResult::with('website')
            ->where('created_at', '>=', $this->startedAt)
            ->get();

        if ($results->isEmpty()) {
            return;
        }

        $reportService->generateAndSend($results, $this->triggeredBy);
    }
}
