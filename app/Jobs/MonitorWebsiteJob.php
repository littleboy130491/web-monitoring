<?php

namespace App\Jobs;

use App\Models\Website;
use App\Models\MonitoringResult;
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

        MonitoringResult::create($result);
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

            Browsershot::url($this->website->url)
                ->setChromePath($chromePath)
                ->noSandbox()
                ->dismissDialogs()
                ->windowSize(1920, 1080)
                ->userAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36')
                ->waitUntilNetworkIdle(false)
                ->timeout(30)
                ->setOption('headless', true)
                ->setOption('no-first-run', true)
                ->setOption('disable-gpu', true)
                ->setOption('disable-dev-shm-usage', true)
                ->setOption('disable-extensions', true)
                ->setOption('disable-web-security', true)
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
            \Log::error("Screenshot failed for {$this->website->name}: " . $e->getMessage());
            \Log::error("Screenshot stack trace: " . $e->getTraceAsString());
            return null;
        }
    }

    private function getChromePath(): ?string
    {
        // Common Chrome/Chromium paths to check
        $paths = [
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable', 
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/snap/bin/chromium',
            '/opt/google/chrome/chrome',
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
        } catch (\Exception $e) {
            \Log::error("Error finding Chrome: " . $e->getMessage());
        }

        \Log::error("No Chrome/Chromium found in any common locations");
        return null;
    }
}