<?php

namespace App\Filament\Actions;

use App\Jobs\GenerateMonitoringReportJob;
use App\Jobs\MonitorWebsiteJob;
use App\Models\Website;
use Filament\Notifications\Notification;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;

class MonitoringActions
{
    public static function dispatchMonitor(Website $website, bool $screenshot = false): void
    {
        MonitorWebsiteJob::dispatch($website, $screenshot, 30);

        Notification::make()
            ->title($screenshot ? 'Monitoring with Screenshot Queued' : 'Monitoring Queued')
            ->body("Website '{$website->url}' has been queued for monitoring" . ($screenshot ? ' with screenshot' : ''))
            ->success()
            ->send();
    }

    public static function dispatchMonitorAll(bool $screenshot = false): int
    {
        $websites = Website::where('is_active', true)->get();
        $count    = $websites->count();

        if ($count === 0) {
            Notification::make()
                ->title('No Active Websites')
                ->body('There are no active websites to monitor.')
                ->warning()
                ->send();

            return 0;
        }

        $startedAt = now();

        $jobs = $websites->map(fn($w) => new MonitorWebsiteJob($w, $screenshot, 30))->all();

        Bus::batch($jobs)
            ->then(fn(Batch $batch) => GenerateMonitoringReportJob::dispatch($startedAt, 'manual'))
            ->name($screenshot ? 'Monitor All (+ Screenshots)' : 'Monitor All')
            ->dispatch();

        Notification::make()
            ->title($screenshot ? 'Monitoring with Screenshots Started' : 'Monitoring Started')
            ->body("Queued {$count} websites for monitoring" . ($screenshot ? ' with screenshots' : '') . '. A report will be emailed when complete.')
            ->success()
            ->send();

        return $count;
    }
}
