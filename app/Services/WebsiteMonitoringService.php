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

            // Domain expiry via WHOIS
            $domainInfo = $this->getDomainInfo($website->url);
            $result['domain_expires_at'] = $domainInfo['domain_expires_at'];
            $result['domain_days_until_expiry'] = $domainInfo['domain_days_until_expiry'];

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
            'domain_expires_at' => null,
            'domain_days_until_expiry' => null,
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
            $filename = 'screenshots/' . $website->id . '_' . now()->format('Y-m-d_H-i-s') . '.jpg';
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
                ->windowSize(1280, 720)
                ->waitUntilNetworkIdle(false)
                ->timeout(20)
                ->setOption('no-first-run', true)
                ->setOption('disable-gpu', true)
                ->setOption('disable-dev-shm-usage', true)
                ->setOption('ignore-certificate-errors', true)
                ->setOption('ignore-ssl-errors', true)
                ->setScreenshotType('jpeg', quality: 70)
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
     * Get domain expiry information via WHOIS
     */
    private function getDomainInfo(string $url): array
    {
        $result = [
            'domain_expires_at' => null,
            'domain_days_until_expiry' => null,
        ];

        try {
            $host = parse_url($url, PHP_URL_HOST);
            if (!$host) {
                return $result;
            }

            // Remove port if present
            $host = preg_replace('/:\d+$/', '', $host);

            // Extract registrable domain (strip subdomains)
            $parts = explode('.', $host);
            if (count($parts) < 2) {
                return $result;
            }
            $domain = implode('.', array_slice($parts, -2));
            $tld = strtolower(end($parts));

            // Map of TLDs to their WHOIS servers
            $whoisServers = [
                'com' => 'whois.verisign-grs.com',
                'net' => 'whois.verisign-grs.com',
                'org' => 'whois.pir.org',
                'info' => 'whois.afilias.net',
                'biz' => 'whois.biz',
                'io'  => 'whois.nic.io',
                'co'  => 'whois.nic.co',
                'us'  => 'whois.nic.us',
                'uk'  => 'whois.nic.uk',
                'au'  => 'whois.auda.org.au',
                'de'  => 'whois.denic.de',
                'fr'  => 'whois.nic.fr',
                'nl'  => 'whois.domain-registry.nl',
                'ca'  => 'whois.cira.ca',
                'app' => 'whois.nic.google',
                'dev' => 'whois.nic.google',
                'id'  => 'whois.id',
                'me'  => 'whois.nic.me',
                'tv'  => 'whois.nic.tv',
                'cc'  => 'whois.nic.cc',
                'mobi' => 'whois.dotmobiregistry.net',
                'name' => 'whois.nic.name',
                'pro'  => 'whois.registry.pro',
            ];

            $whoisServer = $whoisServers[$tld] ?? null;

            // For unknown TLDs, try IANA to find the WHOIS server
            if (!$whoisServer) {
                $ianaResponse = $this->queryWhois('whois.iana.org', $tld, 5);
                if ($ianaResponse && preg_match('/whois:\s+(\S+)/i', $ianaResponse, $m)) {
                    $whoisServer = trim($m[1]);
                }
            }

            if (!$whoisServer) {
                return $result;
            }

            $response = $this->queryWhois($whoisServer, $domain, 10);
            if (!$response) {
                return $result;
            }

            // Try multiple common expiry date patterns
            $patterns = [
                '/Registry Expiry Date:\s*(.+)/i',
                '/Expiry Date:\s*(.+)/i',
                '/Expiration Date:\s*(.+)/i',
                '/Expires On:\s*(.+)/i',
                '/expires:\s*(.+)/i',
                '/paid-till:\s*(.+)/i',
                '/expire:\s*(.+)/i',
                '/Registrar Registration Expiration Date:\s*(.+)/i',
            ];

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $response, $matches)) {
                    $expiryStr = trim($matches[1]);
                    // Strip timezone info in brackets or extra trailing text
                    $expiryStr = preg_replace('/\s*\(.*\)\s*$/', '', $expiryStr);
                    $expiryStr = trim($expiryStr);

                    $expiryTimestamp = strtotime($expiryStr);
                    if ($expiryTimestamp && $expiryTimestamp > 0) {
                        $result['domain_expires_at'] = date('Y-m-d', $expiryTimestamp);
                        $result['domain_days_until_expiry'] = (int) (($expiryTimestamp - time()) / 86400);
                        break;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning("Domain WHOIS check failed for {$url}: " . $e->getMessage());
        }

        return $result;
    }

    /**
     * Query a WHOIS server and return the raw response
     */
    private function queryWhois(string $server, string $query, int $timeout = 10): ?string
    {
        $socket = @fsockopen($server, 43, $errno, $errstr, $timeout);
        if (!$socket) {
            return null;
        }

        stream_set_timeout($socket, $timeout);
        fwrite($socket, $query . "\r\n");

        $response = '';
        while (!feof($socket)) {
            $chunk = fgets($socket, 4096);
            if ($chunk === false) {
                break;
            }
            $response .= $chunk;
        }
        fclose($socket);

        return $response ?: null;
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