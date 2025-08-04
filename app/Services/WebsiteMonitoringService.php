<?php

namespace App\Services;

use App\Models\Website;
use App\Models\MonitoringResult;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Spatie\Browsershot\Browsershot;
use Illuminate\Support\Facades\Log;

class WebsiteMonitoringService
{
    private Client $client;

    public function __construct()
    {
        $this->client = new Client();
    }

    /**
     * Monitor a website and return the monitoring result
     */
    public function monitor(Website $website, int $timeout = 30, bool $takeScreenshot = false): MonitoringResult
    {
        $startTime = microtime(true);
        $result = $this->initializeResult($website);

        try {
            $options = [
                'timeout' => $timeout,
                'headers' => $website->headers ?? [],
                'verify' => true,
                'http_errors' => false,
            ];

            $response = $this->client->get($website->url, $options);
            $endTime = microtime(true);

            $result['response_time'] = (int) (($endTime - $startTime) * 1000);
            $result['status_code'] = $response->getStatusCode();
            $result['headers'] = json_encode($response->getHeaders());

            $content = $response->getBody()->getContents();
            $result['content_hash'] = hash('sha256', $content);

            // Check if content changed
            $result['content_changed'] = $this->checkContentChanged($website, $result['content_hash']);

            // Determine status
            $result['status'] = $this->determineStatus($response->getStatusCode());
            if ($result['status'] === 'down') {
                $result['error_message'] = "HTTP {$response->getStatusCode()} error";
            }

            // SSL info for HTTPS
            if (str_starts_with($website->url, 'https://')) {
                $sslInfo = $this->getSSLInfo($website->url);
                $result['ssl_info'] = $sslInfo ? json_encode($sslInfo) : null;
            }

            // Take screenshot if requested and website is up
            if ($takeScreenshot && $result['status'] === 'up') {
                $result['screenshot_path'] = $this->takeScreenshot($website);
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

        return MonitoringResult::create($result);
    }

    /**
     * Initialize the result array with default values
     */
    private function initializeResult(Website $website): array
    {
        return [
            'website_id' => $website->id,
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
    }

    /**
     * Check if content has changed from the last monitoring result
     */
    private function checkContentChanged(Website $website, string $contentHash): bool
    {
        $lastResult = $website->monitoringResults()->latest()->first();
        return $lastResult && $lastResult->content_hash !== $contentHash;
    }

    /**
     * Determine website status based on HTTP status code
     */
    private function determineStatus(int $statusCode): string
    {
        if ($statusCode >= 200 && $statusCode < 300) {
            return 'up';
        } elseif ($statusCode >= 400) {
            return 'down';
        } else {
            return 'warning';
        }
    }

    /**
     * Get SSL certificate information for HTTPS websites
     */
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

    /**
     * Take a screenshot of the website
     */
    private function takeScreenshot(Website $website): ?string
    {
        try {
            $filename = 'screenshots/' . $website->id . '_' . now()->format('Y-m-d_H-i-s') . '.png';
            $fullPath = storage_path('app/public/' . $filename);

            // Ensure screenshots directory exists
            $dir = dirname($fullPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $chromePath = $this->findChromePath();
            if (!$chromePath) {
                Log::error('No Chrome or Chromium installation found on system.');
                return null;
            }

            Browsershot::url($website->url)
                ->setChromePath($chromePath)
                ->noSandbox()
                ->dismissDialogs()
                ->windowSize(1920, 1080)
                ->waitUntilNetworkIdle(false)
                ->timeout(30)
                ->setOption('no-first-run', true)
                ->setOption('disable-gpu', true)
                ->setOption('disable-dev-shm-usage', true)
                ->save($fullPath);

            // Verify the file was created and has content
            if (file_exists($fullPath) && filesize($fullPath) > 0) {
                Log::info("Screenshot saved successfully: {$filename} (" . filesize($fullPath) . " bytes)");
                return $filename;
            } else {
                Log::error("Screenshot file was created but is empty: {$filename}");
                return null;
            }

        } catch (\Exception $e) {
            Log::error("Screenshot failed for {$website->url}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Find the Chrome executable path
     */
    private function findChromePath(): ?string
    {
        $possiblePaths = [
            '/usr/bin/google-chrome-stable',
            '/usr/bin/google-chrome',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
        ];

        foreach ($possiblePaths as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }
}