<?php

namespace App\Jobs;

use App\Models\Website;
use App\Models\MonitoringResult;
use App\Models\User;
use App\Services\WebsiteMonitoringService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Filament\Notifications\Notification;

class MonitorWebsiteJob implements ShouldQueue
{
    use Queueable;

    public $timeout = 120; // 2 minutes timeout
    public $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Website $website,
        public bool $takeScreenshot = false,
        public int $requestTimeout = 30
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(WebsiteMonitoringService $monitoringService): void
    {
        $monitoringResult = $monitoringService->monitor(
            $this->website,
            $this->requestTimeout,
            $this->takeScreenshot
        );

        // Send notifications for status changes and important events
        $this->sendNotifications($monitoringResult);
    }


    private function sendNotifications(MonitoringResult $result): void
    {
        // Get the previous monitoring result to check for status changes
        $previousResult = $this->website->monitoringResults()
            ->where('id', '!=', $result->id)
            ->latest()
            ->first();

        $users = User::all(); // Send to all users, you can customize this logic

        foreach ($users as $user) {
            // Notification for status changes (down to up, up to down, etc.)
            if ($previousResult && $previousResult->status !== $result->status) {
                $this->sendStatusChangeNotification($user, $result, $previousResult);
            }

            // Notification for errors
            if ($result->status === 'error') {
                $this->sendErrorNotification($user, $result);
            }

            // Notification for content changes
            if ($result->content_changed) {
                $this->sendContentChangeNotification($user, $result);
            }

            // Notification for successful monitoring with screenshot
            if ($result->status === 'up' && $result->screenshot_path) {
                $this->sendScreenshotNotification($user, $result);
            }

            // Notification for domain expiring soon (≤ 7 days)
            if ($result->domain_days_until_expiry !== null && $result->domain_days_until_expiry <= 7) {
                $this->sendDomainExpiryNotification($user, $result);
            }

            // Notification for broken assets (e.g. stale Elementor CSS)
            $scanResults = $result->scan_results;
            if (!empty($scanResults['broken_assets'])) {
                $this->sendBrokenAssetsNotification($user, $result, $scanResults['broken_assets']);
            }
        }
    }

    private function sendStatusChangeNotification(User $user, MonitoringResult $result, MonitoringResult $previousResult): void
    {
        $color = match ($result->status) {
            'up' => 'success',
            'down' => 'danger',
            'error' => 'danger',
            'warning' => 'warning',
            default => 'info',
        };

        $icon = match ($result->status) {
            'up' => 'heroicon-o-check-circle',
            'down' => 'heroicon-o-x-circle',
            'error' => 'heroicon-o-exclamation-triangle',
            'warning' => 'heroicon-o-exclamation-circle',
            default => 'heroicon-o-information-circle',
        };

        Notification::make()
            ->title('Website Status Changed')
            ->body("{$this->website->url} changed from {$previousResult->status} to {$result->status}")
            ->icon($icon)
            ->color($color)
            ->sendToDatabase($user);
    }

    private function sendErrorNotification(User $user, MonitoringResult $result): void
    {
        Notification::make()
            ->title('Website Monitoring Error')
            ->body("{$this->website->url}: {$result->error_message}")
            ->icon('heroicon-o-exclamation-triangle')
            ->color('danger')
            ->sendToDatabase($user);
    }

    private function sendContentChangeNotification(User $user, MonitoringResult $result): void
    {
        Notification::make()
            ->title('Content Changed')
            ->body("{$this->website->url} content has changed")
            ->icon('heroicon-o-document-text')
            ->color('info')
            ->sendToDatabase($user);
    }

    private function sendScreenshotNotification(User $user, MonitoringResult $result): void
    {
        Notification::make()
            ->title('Screenshot Captured')
            ->body("{$this->website->url} is up and screenshot captured successfully")
            ->icon('heroicon-o-camera')
            ->color('success')
            ->sendToDatabase($user);
    }

    private function sendBrokenAssetsNotification(User $user, MonitoringResult $result, array $brokenAssets): void
    {
        $count = count($brokenAssets);
        $types = implode(', ', array_unique(array_column($brokenAssets, 'type')));
        $sample = $brokenAssets[0]['url'] ?? '';

        Notification::make()
            ->title('Broken Assets Detected')
            ->body("{$this->website->url} has {$count} broken asset(s) returning 404 ({$types}). e.g. {$sample}")
            ->icon('heroicon-o-exclamation-triangle')
            ->color('warning')
            ->sendToDatabase($user);
    }

    private function sendDomainExpiryNotification(User $user, MonitoringResult $result): void
    {
        $days = $result->domain_days_until_expiry;
        $expiresAt = $result->domain_expires_at?->format('Y-m-d');

        if ($days <= 0) {
            $body = "{$this->website->url} domain has EXPIRED (expired on {$expiresAt})";
            $color = 'danger';
        } else {
            $body = "{$this->website->url} domain expires in {$days} day(s) on {$expiresAt}";
            $color = 'warning';
        }

        Notification::make()
            ->title('Domain Expiring Soon')
            ->body($body)
            ->icon('heroicon-o-calendar')
            ->color($color)
            ->sendToDatabase($user);
    }
}