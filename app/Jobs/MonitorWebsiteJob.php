<?php

namespace App\Jobs;

use App\Models\Website;
use App\Models\MonitoringResult;
use App\Models\User;
use Filament\Notifications\Notification;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Spatie\Browsershot\Browsershot;

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

            // Take screenshot if requested
            if ($this->takeScreenshot) {
                \Log::info("Screenshot requested for {$this->website->name}");
                $result['screenshot_path'] = $this->takeScreenshot();
                \Log::info("Screenshot result: " . ($result['screenshot_path'] ?? 'null'));
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

        // Send notifications to all users
        $users = User::all();

        foreach ($users as $user) {
            $title = match ($result['status']) {
                'up' => "✅ {$this->website->name} is Online",
                'down' => "❌ {$this->website->name} is Down",
                'error' => "⚠️ Failed to Monitor {$this->website->name}",
                'warning' => "⚠️ {$this->website->name} has Issues",
                default => "📊 Monitoring Complete for {$this->website->name}",
            };

            $body = "Status: {$result['status']}";
            if ($result['response_time']) {
                $body .= " | Response: {$result['response_time']}ms";
            }
            if ($result['error_message']) {
                $body .= " | Error: {$result['error_message']}";
            }
            if ($result['content_changed']) {
                $body .= " | Content changed!";
            }

            $color = match ($result['status']) {
                'up' => 'success',
                'down' => 'danger',
                'error' => 'danger',
                'warning' => 'warning',
                default => 'info',
            };

            $user->notify(
                Notification::make()
                    ->title($title)
                    ->body($body)
                    ->color($color)
                    ->toDatabase()
            );
        }

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

            // Check if we're in a Docker/Sail environment
            $chromePath = $this->getChromePath();
            if (!$chromePath) {
                \Log::warning("Chrome not found - skipping screenshot for {$this->website->name}");
                return null;
            }

            \Log::info("Taking screenshot for {$this->website->name} using Chrome at: {$chromePath}");

            // Create Browsershot instance with improved configuration
            $browsershot = Browsershot::url($this->website->url)
                ->setChromePath($chromePath)
                ->noSandbox()
                ->dismissDialogs()
                ->windowSize(1920, 1080)
                ->userAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36')
                ->waitUntilNetworkIdle(true) // Changed to true for better page loading
                ->timeout(60) // Increased timeout
                ->setOption('args', [
                    '--disable-web-security',
                    '--disable-features=IsolateOrigins',
                    '--disable-features=site-per-process',
                    '--no-first-run',
                    '--disable-gpu',
                    '--disable-dev-shm-usage',
                    '--disable-extensions',
                    '--disable-background-timer-throttling',
                    '--disable-backgrounding-occluded-windows',
                    '--disable-renderer-backgrounding'
                ])
                ->setOption('headless', 'new'); // Use new headless mode

            // Take the screenshot
            $browsershot->save($fullPath);

            // Verify the file was created and has content
            if (file_exists($fullPath) && filesize($fullPath) > 0) {
                \Log::info("Screenshot saved successfully: {$filename} (" . filesize($fullPath) . " bytes)");
                return $filename;
            } else {
                \Log::error("Screenshot file was created but is empty: {$filename}");
                // Try to get more information about what went wrong
                if (file_exists($fullPath)) {
                    \Log::error("File exists but is empty. Size: " . filesize($fullPath) . " bytes");
                } else {
                    \Log::error("File was not created at all");
                }
                return null;
            }

        } catch (\Exception $e) {
            \Log::error("Screenshot failed for {$this->website->name}: " . $e->getMessage());
            \Log::error("Screenshot stack trace: " . $e->getTraceAsString());
            // Log additional debugging information
            \Log::error("Website URL: {$this->website->url}");
            \Log::error("Full path: {$fullPath}");
            return null;
        }
    }

    private function getChromePath(): ?string
    {
        // Check if we're in a Docker/Sail environment
        $isSail = env('LARAVEL_SAIL', false);

        if ($isSail) {
            // In Sail environment, Chromium is typically at this path
            $sailChromePath = '/usr/bin/chromium-browser';
            if (file_exists($sailChromePath) && is_executable($sailChromePath)) {
                \Log::info("Found Chrome in Sail environment: {$sailChromePath}");
                return $sailChromePath;
            }
        }

        // Common Chrome/Chromium paths to check
        $paths = [
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/snap/bin/chromium',
            '/opt/google/chrome/chrome',
            '/usr/bin/chrome',
            '/usr/local/bin/chrome',
            '/usr/local/bin/google-chrome'
        ];

        foreach ($paths as $path) {
            if (file_exists($path) && is_executable($path)) {
                \Log::info("Found Chrome at: {$path}");
                return $path;
            }
        }

        // Try to find Chrome using which command
        try {
            $result = shell_exec('which google-chrome 2>/dev/null');
            if ($result && trim($result)) {
                \Log::info("Found Chrome via which: " . trim($result));
                return trim($result);
            }

            $result = shell_exec('which chromium 2>/dev/null');
            if ($result && trim($result)) {
                \Log::info("Found Chromium via which: " . trim($result));
                return trim($result);
            }

            $result = shell_exec('which chrome 2>/dev/null');
            if ($result && trim($result)) {
                \Log::info("Found Chrome via which: " . trim($result));
                return trim($result);
            }
        } catch (\Exception $e) {
            \Log::error("Error finding Chrome: " . $e->getMessage());
        }

        // Check if we're in a Docker/Sail environment and use the appropriate path
        if (file_exists('/usr/bin/chromium-browser') && is_executable('/usr/bin/chromium-browser')) {
            \Log::info("Using Docker Chromium path: /usr/bin/chromium-browser");
            return '/usr/bin/chromium-browser';
        }

        \Log::error("No Chrome/Chromium found in any common locations");
        return null;
    }
}