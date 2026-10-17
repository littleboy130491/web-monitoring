<?php

namespace App\Jobs;

use App\Models\MonitoringResult;
use App\Models\User;
use App\Services\Ai\MonitoringAnalysisService;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AnalyzeMonitoringResultJob implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public $timeout = 180;

    public function backoff(): array
    {
        return [30, 120];
    }

    public function __construct(public MonitoringResult $result) {}

    public function handle(MonitoringAnalysisService $analysis): void
    {
        if (! $analysis->isEnabled()) {
            return;
        }

        $outcome = $analysis->analyze($this->result);

        $this->notify($outcome);
    }

    private function notify(array $outcome): void
    {
        $threshold = MonitoringAnalysisService::severityRank(config('ai.notify_min_severity', 'high'));
        $reached = MonitoringAnalysisService::severityRank($outcome['severity']) >= $threshold;

        if (! $reached && ! $outcome['compromised']) {
            return;
        }

        $url = $this->result->website->url;
        $title = match (true) {
            $outcome['compromised'] => 'AI: Website May Be Compromised',
            $outcome['layout_broken'] ?? false => 'AI: Page Layout Broken',
            default => 'AI: '.ucfirst($outcome['severity']).' Severity Issue',
        };

        $body = "{$url}: {$outcome['summary']}".(! empty($outcome['suggested_fix']) ? " Fix: {$outcome['suggested_fix']}" : '');

        foreach (User::all() as $user) {
            Notification::make()
                ->title($title)
                ->body($body)
                ->icon($outcome['compromised'] ? 'heroicon-o-shield-exclamation' : 'heroicon-o-sparkles')
                ->color($outcome['severity'] === 'critical' ? 'danger' : 'warning')
                ->sendToDatabase($user);
        }
    }
}
