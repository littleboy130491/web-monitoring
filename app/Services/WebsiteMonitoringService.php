<?php

namespace App\Services;

use App\Models\Website;
use App\Models\MonitoringResult;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Spatie\Browsershot\Browsershot;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WebsiteMonitoringService
{
    private Client $client;

    /** Change threshold (%) above which a page is considered significantly changed */
    private const CHANGE_THRESHOLD = 10.0;

    /** Max same-domain CSS/JS assets to HEAD-check per page */
    private const MAX_ASSET_CHECKS = 20;

    /** Max text size (bytes) passed to similar_text to keep it fast */
    private const MAX_DIFF_BYTES = 51200; // 50 KB

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

            $html = $response->getBody()->getContents();
            $result['content_hash'] = hash('sha256', $html);

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

            // Deep scan: save page text snapshots and diff against previous
            try {
                $scanData = $this->performDeepScan($website, $html);
                $result['content_changed'] = $scanData['any_significant_change'];
                $result['scan_results'] = $scanData;
            } catch (\Exception $e) {
                Log::warning("Deep scan failed for {$website->url}: " . $e->getMessage());
                // Leave scan_results null; do not affect status
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

    // -------------------------------------------------------------------------
    // Deep scan
    // -------------------------------------------------------------------------

    /**
     * Perform a deep scan: save text snapshots of the main page and up to 3 nav
     * pages, diff each against its previous snapshot, and check for broken assets.
     */
    private function performDeepScan(Website $website, string $mainHtml): array
    {
        $websiteSlug = $this->websiteSlug($website->url);
        $pages = [];

        // --- Main page ---
        $pages[] = $this->scanPage('home', $websiteSlug, $mainHtml, $website->url);

        // --- First 3 <nav> links (same domain) ---
        $navLinks = $this->extractNavLinks($mainHtml, $website->url);
        foreach ($navLinks as $navUrl) {
            try {
                $navResponse = $this->client->get($navUrl, [
                    'timeout' => 15,
                    'http_errors' => false,
                    'allow_redirects' => true,
                ]);
                $navHtml = $navResponse->getBody()->getContents();
                $pageSlug = $this->pageSlug($navUrl);
                $pages[] = $this->scanPage($pageSlug, $websiteSlug, $navHtml, $navUrl);
            } catch (\Exception $e) {
                Log::warning("Deep scan: failed to fetch nav link {$navUrl}: " . $e->getMessage());
            }
        }

        // --- Broken asset detection (same-domain CSS/JS → HEAD check) ---
        $brokenAssets = $this->checkBrokenAssets($mainHtml, $website->url);

        return [
            'pages' => $pages,
            'broken_assets' => $brokenAssets,
            'any_significant_change' => collect($pages)->contains('significant', true),
            'has_broken_assets' => count($brokenAssets) > 0,
            'scanned_at' => now()->toISOString(),
        ];
    }

    /**
     * Strip HTML to visible text, save as a timestamped snapshot, diff against
     * the most recent previous snapshot for the same page, return result data.
     */
    private function scanPage(string $pageSlug, string $websiteSlug, string $html, string $url): array
    {
        $text = $this->stripToText($html);
        $dir = storage_path("app/scans/{$websiteSlug}/{$pageSlug}");

        // Find previous snapshot BEFORE writing the new one
        $previousFile = $this->findLatestFile($dir);

        // Save current snapshot
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filename = now()->format('Y-m-d_H-i-s') . '.txt';
        file_put_contents("{$dir}/{$filename}", $text);

        // Compare with previous
        $changePercent = 0.0;
        $previousFileFound = $previousFile !== null;

        if ($previousFile !== null) {
            $previousText = file_get_contents($previousFile);
            $changePercent = $this->diffPercent($previousText, $text);
        }

        return [
            'url' => $url,
            'slug' => $pageSlug,
            'change_percent' => $changePercent,
            'significant' => $changePercent > self::CHANGE_THRESHOLD,
            'previous_file_found' => $previousFileFound,
            'snapshot' => "scans/{$websiteSlug}/{$pageSlug}/{$filename}",
        ];
    }

    /**
     * Extract first 3 same-domain <a href> elements inside <nav> tags.
     */
    private function extractNavLinks(string $html, string $baseUrl): array
    {
        $links = [];
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $anchors = $xpath->query('//nav//a[@href]');
        if ($anchors === false) {
            return [];
        }

        $baseParsed = parse_url($baseUrl);
        $baseHost = $baseParsed['host'] ?? '';
        $baseScheme = $baseParsed['scheme'] ?? 'https';

        foreach ($anchors as $anchor) {
            if (count($links) >= 3) {
                break;
            }

            $href = trim($anchor->getAttribute('href'));

            // Skip non-navigable links
            if (empty($href)
                || str_starts_with($href, '#')
                || str_starts_with($href, 'javascript:')
                || str_starts_with($href, 'mailto:')
                || str_starts_with($href, 'tel:')
            ) {
                continue;
            }

            // Resolve to absolute URL
            if (str_starts_with($href, '//')) {
                $href = $baseScheme . ':' . $href;
            } elseif (str_starts_with($href, '/')) {
                $href = $baseScheme . '://' . $baseHost . $href;
            } elseif (!str_starts_with($href, 'http')) {
                continue;
            }

            // Same domain only
            $linkHost = parse_url($href, PHP_URL_HOST);
            if ($linkHost !== $baseHost) {
                continue;
            }

            // No duplicates, no self-link
            $normalized = rtrim($href, '/');
            $normalizedBase = rtrim($baseUrl, '/');
            if ($normalized === $normalizedBase || in_array($href, $links)) {
                continue;
            }

            $links[] = $href;
        }

        return $links;
    }

    /**
     * HEAD-check all same-domain stylesheets and scripts on the page.
     * Returns an array of assets that returned HTTP 404.
     */
    private function checkBrokenAssets(string $html, string $baseUrl): array
    {
        $broken = [];
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $baseHost = parse_url($baseUrl, PHP_URL_HOST);
        $baseScheme = parse_url($baseUrl, PHP_URL_SCHEME) ?? 'https';

        $assetsToCheck = [];

        // Collect <link rel="stylesheet">
        foreach ($dom->getElementsByTagName('link') as $link) {
            if (strtolower($link->getAttribute('rel')) !== 'stylesheet') {
                continue;
            }
            $href = $this->resolveAssetUrl($link->getAttribute('href'), $baseScheme, $baseHost);
            if ($href && parse_url($href, PHP_URL_HOST) === $baseHost) {
                $assetsToCheck[] = ['url' => $href, 'type' => 'css'];
            }
        }

        // Collect <script src>
        foreach ($dom->getElementsByTagName('script') as $script) {
            $src = $this->resolveAssetUrl($script->getAttribute('src'), $baseScheme, $baseHost);
            if ($src && parse_url($src, PHP_URL_HOST) === $baseHost) {
                $assetsToCheck[] = ['url' => $src, 'type' => 'js'];
            }
        }

        foreach (array_slice($assetsToCheck, 0, self::MAX_ASSET_CHECKS) as $asset) {
            try {
                $response = $this->client->head($asset['url'], [
                    'timeout' => 5,
                    'http_errors' => false,
                    'verify' => false,
                ]);
                if ($response->getStatusCode() === 404) {
                    $broken[] = [
                        'url' => $asset['url'],
                        'type' => $asset['type'],
                        'status' => 404,
                    ];
                }
            } catch (\Exception $e) {
                // Skip — asset may just be slow
            }
        }

        return $broken;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Strip HTML to visible plain text */
    private function stripToText(string $html): string
    {
        // Remove <script> and <style> blocks entirely
        $html = preg_replace('/<script\b[^>]*>[\s\S]*?<\/script>/i', '', $html);
        $html = preg_replace('/<style\b[^>]*>[\s\S]*?<\/style>/i', '', $html);
        $text = strip_tags($html);
        // Collapse whitespace
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    /**
     * Calculate the percentage of content that changed between two texts.
     * 0 = identical, 100 = completely different.
     */
    private function diffPercent(string $old, string $new): float
    {
        if ($old === $new) {
            return 0.0;
        }

        // Limit size to keep similar_text fast
        $old = substr($old, 0, self::MAX_DIFF_BYTES);
        $new = substr($new, 0, self::MAX_DIFF_BYTES);

        similar_text($old, $new, $similarity);
        return round(100.0 - $similarity, 2);
    }

    /** Find the most recently created .txt file in a directory, or null. */
    private function findLatestFile(string $directory): ?string
    {
        if (!is_dir($directory)) {
            return null;
        }
        $files = glob($directory . '/*.txt');
        if (empty($files)) {
            return null;
        }
        sort($files); // datetime-prefixed filenames sort chronologically
        return end($files) ?: null;
    }

    /** Derive a filesystem-safe slug from a website's hostname. */
    private function websiteSlug(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?? $url;
        $host = preg_replace('/^www\./', '', $host);
        return Str::slug($host);
    }

    /** Derive a filesystem-safe slug from a page URL path. */
    private function pageSlug(string $url): string
    {
        $path = trim(parse_url($url, PHP_URL_PATH) ?? '/', '/');
        if ($path === '' || $path === '/') {
            return 'home';
        }
        return Str::slug(str_replace('/', '-', $path)) ?: 'home';
    }

    /** Resolve a potentially relative asset URL to an absolute one. */
    private function resolveAssetUrl(string $href, string $scheme, string $host): ?string
    {
        $href = trim($href);
        if (empty($href)) {
            return null;
        }
        if (str_starts_with($href, '//')) {
            return $scheme . ':' . $href;
        }
        if (str_starts_with($href, '/')) {
            return $scheme . '://' . $host . $href;
        }
        if (str_starts_with($href, 'http')) {
            return $href;
        }
        return null;
    }

    // -------------------------------------------------------------------------
    // Existing methods (unchanged)
    // -------------------------------------------------------------------------

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
            'scan_results' => null,
            'content_hash' => null,
            'content_changed' => false,
            'screenshot_path' => null,
        ];
    }

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

            $host = preg_replace('/:\d+$/', '', $host);
            $parts = explode('.', $host);
            if (count($parts) < 2) {
                return $result;
            }

            $tld = strtolower(end($parts));
            $sld = count($parts) >= 3 ? strtolower($parts[count($parts) - 2] . '.' . $tld) : null;

            // Second-level domain WHOIS servers — must be checked before TLD map
            // because domain extraction also differs (3 parts instead of 2)
            $whoisServersBySld = [
                'co.id'  => 'whois.id',
                'or.id'  => 'whois.id',
                'net.id' => 'whois.id',
                'go.id'  => 'whois.id',
                'ac.id'  => 'whois.id',
                'co.uk'  => 'whois.nic.uk',
                'org.uk' => 'whois.nic.uk',
                'me.uk'  => 'whois.nic.uk',
                'com.au' => 'whois.auda.org.au',
                'net.au' => 'whois.auda.org.au',
                'org.au' => 'whois.auda.org.au',
            ];

            $whoisServers = [
                'com'  => 'whois.verisign-grs.com',
                'net'  => 'whois.verisign-grs.com',
                'org'  => 'whois.pir.org',
                'info' => 'whois.afilias.net',
                'biz'  => 'whois.biz',
                'io'   => 'whois.nic.io',
                'co'   => 'whois.registry.co',
                'us'   => 'whois.nic.us',
                'uk'   => 'whois.nic.uk',
                'au'   => 'whois.auda.org.au',
                'de'   => 'whois.denic.de',
                'fr'   => 'whois.nic.fr',
                'nl'   => 'whois.domain-registry.nl',
                'ca'   => 'whois.cira.ca',
                'app'  => 'whois.nic.google',
                'dev'  => 'whois.nic.google',
                'id'   => 'whois.id',
                'me'   => 'whois.nic.me',
                'tv'   => 'whois.nic.tv',
                'cc'   => 'whois.nic.cc',
                'mobi' => 'whois.dotmobiregistry.net',
                'name' => 'whois.nic.name',
                'pro'  => 'whois.registry.pro',
                'law'  => 'whois.nic.law',
                'tax'  => 'whois.nic.tax',
            ];

            // SLD match: use 3-part registrable domain (e.g. example.co.id)
            if ($sld && isset($whoisServersBySld[$sld])) {
                $whoisServer = $whoisServersBySld[$sld];
                $domain = implode('.', array_slice($parts, -3));
            } else {
                $domain = implode('.', array_slice($parts, -2));
                $whoisServer = $whoisServers[$tld] ?? null;

                // Fallback: ask IANA for the authoritative WHOIS server
                if (!$whoisServer) {
                    $ianaResponse = $this->queryWhois('whois.iana.org', $tld, 5);
                    if ($ianaResponse && preg_match('/whois:\s+(\S+)/i', $ianaResponse, $m)) {
                        $whoisServer = trim($m[1]);
                    }
                }
            }

            if (!$whoisServer) {
                return $result;
            }

            $response = $this->queryWhois($whoisServer, $domain, 10);
            if (!$response) {
                return $result;
            }

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

    private function takeScreenshot(Website $website): ?string
    {
        try {
            $filename = 'screenshots/' . $website->id . '_' . now()->format('Y-m-d_H-i-s') . '.jpg';
            $fullPath = storage_path('app/public/' . $filename);

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
