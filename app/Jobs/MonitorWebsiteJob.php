<?php

namespace App\Jobs;

use App\Models\Website;
use App\Models\MonitoringResult;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Spatie\Browsershot\Browsershot;
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
    public function handle(): void
    {
        $client = new Client();
        $startTime = microtime(true);

        $result = [
            'website_id' => $this->website->id,
            'checked_at' => now(),
            'status' => 'unknown',
            'response_time' => null,
            'status_code' => null,
            'error_message' => null,
            'headers' => null,
            'ssl_info' => null,
            'content_hash' => null,
            'content_changed' => false,
            'screenshot_path' => null,
        ];

        try {
            $options = [
                'timeout' => $this->requestTimeout,
                'headers' => $this->website->headers ?? [],
                'verify' => true,
                'http_errors' => false,
            ];

            $response = $client->get($this->website->url, $options);
            $endTime = microtime(true);

            $result['response_time'] = (int) (($endTime - $startTime) * 1000);
            $result['status_code'] = $response->getStatusCode();
            $result['headers'] = json_encode($response->getHeaders());

            $content = $response->getBody()->getContents();
            $result['content_hash'] = hash('sha256', $content);

            // Check if content changed
            $lastResult = $this->website->monitoringResults()->latest()->first();
            if ($lastResult && $lastResult->content_hash !== $result['content_hash']) {
                $result['content_changed'] = true;
            }

            // Determine status
            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                $result['status'] = 'up';
            } elseif ($response->getStatusCode() >= 400) {
                $result['status'] = 'down';
                $result['error_message'] = "HTTP {$response->getStatusCode()} error";
            } else {
                $result['status'] = 'warning';
            }

            // SSL info for HTTPS
            if (str_starts_with($this->website->url, 'https://')) {
                $sslInfo = $this->getSSLInfo($this->website->url);
                $result['ssl_info'] = $sslInfo ? json_encode($sslInfo) : null;
            }

            // Take screenshot if requested and website is up
            if ($this->takeScreenshot && $result['status'] === 'up') {
                \Log::info("Screenshot requested for {$this->website->url} (status: up)");
                $result['screenshot_path'] = $this->takeScreenshot();
                \Log::info("Screenshot result: " . ($result['screenshot_path'] ?? 'null'));
            } elseif ($this->takeScreenshot && $result['status'] !== 'up') {
                \Log::info("Screenshot skipped for {$this->website->url} (status: {$result['status']})");
            }

        } catch (GuzzleException $e) {
            $result['status'] = 'error';
            $result['error_message'] = $e->getMessage();
            $result['response_time'] = (int) ((microtime(true) - $startTime) * 1000);
        } catch (\Exception $e) {
            $result['status'] = 'error';
            $result['error_message'] = 'Monitoring failed: ' . $e->getMessage();
            $result['response_time'] = (int) ((microtime(true) - $startTime) * 1000);
        }

        $monitoringResult = MonitoringResult::create($result);

        // Send notifications for status changes and important events
        $this->sendNotifications($monitoringResult);
    }

    private function getSSLInfo(string $url): ?array
    {
        try {
            $parsedUrl = parse_url($url);
            $host = $parsedUrl['host'];
            $port = $parsedUrl['port'] ?? 443;

            $context = stream_context_create([
                'ssl' => [
                    'capture_peer_cert' => true,
                    'capture_peer_cert_chain' => true,
                ],
            ]);

            $client = stream_socket_client(
                "ssl://{$host}:{$port}",
                $errno,
                $errstr,
                30,
                STREAM_CLIENT_CONNECT,
                $context
            );

            if ($client) {
                $cert = stream_context_get_params($client)['options']['ssl']['peer_certificate'];
                $certInfo = openssl_x509_parse($cert);

                return [
                    'issuer' => $certInfo['issuer']['CN'] ?? 'Unknown',
                    'subject' => $certInfo['subject']['CN'] ?? 'Unknown',
                    'valid_from' => date('Y-m-d H:i:s', $certInfo['validFrom_time_t']),
                    'valid_to' => date('Y-m-d H:i:s', $certInfo['validTo_time_t']),
                    'expires_in_days' => (int) (($certInfo['validTo_time_t'] - time()) / 86400),
                ];
            }
        } catch (\Exception $e) {
            // SSL info collection failed, continue without it
        }

        return null;
    }

    private function takeScreenshot(): ?string
    {
        try {
            $filename = 'screenshots/' . $this->website->id . '_' . now()->format('Y-m-d_H-i-s') . '.png';
            $fullPath = storage_path('app/public/' . $filename);

            // Ensure screenshots directory exists
            $dir = dirname($fullPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            \Log::info("Taking screenshot for {$this->website->url}");

            $possiblePaths = [
                '/usr/bin/google-chrome',
                '/usr/bin/google-chrome-stable',
                '/usr/bin/chromium-browser',
                '/usr/bin/chromium',
            ];

            $chromePath = null;

            foreach ($possiblePaths as $path) {
                if (is_executable($path)) {
                    $chromePath = $path;
                    break;
                }
            }

            if (!$chromePath) {
                Log::error('No Chrome or Chromium installation found on system.');
                return;
            }

            // Use Browsershot with Docker-compatible Chrome settings
            Browsershot::url($this->website->url)
                ->setChromePath($chromePath)
                ->noSandbox()
                ->setOption('disable-dev-shm-usage', true)
                ->setOption('disable-gpu', true)
                ->save($fullPath);

            // Verify the file was created and has content
            if (file_exists($fullPath) && filesize($fullPath) > 0) {
                \Log::info("Screenshot saved successfully: {$filename} (" . filesize($fullPath) . " bytes)");
                return $filename;
            } else {
                \Log::error("Screenshot file was created but is empty: {$filename}");
                return null;
            }

        } catch (\Exception $e) {
            \Log::error("Screenshot failed for {$this->website->url}: " . $e->getMessage());
            return null;
        }
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
}