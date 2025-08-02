<?php

namespace App\Console\Commands;

use App\Models\Website;
use App\Models\MonitoringResult;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Spatie\Browsershot\Browsershot;

class MonitorWebsites extends Command
{
    protected $signature = 'monitor:websites 
                            {--id= : Monitor specific website ID}
                            {--screenshot : Take screenshots of websites}
                            {--timeout=30 : Request timeout in seconds}';

    protected $description = 'Monitor websites for status, health, content changes, and take screenshots';

    private Client $client;

    public function __construct()
    {
        parent::__construct();
        $this->client = new Client();
    }

    public function handle(): int
    {
        $this->info('Starting website monitoring...');

        $websites = $this->getWebsitesToMonitor();

        if ($websites->isEmpty()) {
            $this->warn('No active websites found to monitor.');
            return Command::SUCCESS;
        }

        $this->info("Monitoring {$websites->count()} websites...");

        $bar = $this->output->createProgressBar($websites->count());
        $bar->start();

        foreach ($websites as $website) {
            $this->monitorWebsite($website);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('Website monitoring completed!');

        return Command::SUCCESS;
    }

    private function getWebsitesToMonitor()
    {
        if ($websiteId = $this->option('id')) {
            return Website::where('id', $websiteId)->where('is_active', true)->get();
        }

        return Website::where('is_active', true)->get();
    }

    private function monitorWebsite(Website $website): void
    {
        $startTime = microtime(true);
        $result = [
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

        try {
            $options = [
                'timeout' => (int) $this->option('timeout'),
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
            $lastResult = $website->monitoringResults()->latest()->first();
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
            if (str_starts_with($website->url, 'https://')) {
                $sslInfo = $this->getSSLInfo($website->url);
                $result['ssl_info'] = $sslInfo ? json_encode($sslInfo) : null;
            }

            // Take screenshot if requested
            if ($this->option('screenshot')) {
                $result['screenshot_path'] = $this->takeScreenshot($website);
            }

        } catch (GuzzleException $e) {
            $result['status'] = 'error';
            $result['error_message'] = $e->getMessage();
            $result['response_time'] = (int) ((microtime(true) - $startTime) * 1000);
        }

        MonitoringResult::create($result);

        $this->displayResult($website, $result);
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

            Browsershot::url($website->url)
                ->setChromePath('/usr/bin/google-chrome')
                ->windowSize(1920, 1080)
                ->waitUntilNetworkIdle()
                ->timeout(60)
                ->save($fullPath);

            return $filename;
        } catch (\Exception $e) {
            $this->warn("Screenshot failed for {$website->name}: " . $e->getMessage());
            return null;
        }
    }

    private function displayResult(Website $website, array $result): void
    {
        $status = match($result['status']) {
            'up' => '<fg=green>UP</fg=green>',
            'down' => '<fg=red>DOWN</fg=red>',
            'error' => '<fg=red>ERROR</fg=red>',
            'warning' => '<fg=yellow>WARNING</fg=yellow>',
            default => '<fg=gray>UNKNOWN</fg=gray>',
        };

        $this->newLine();
        $this->line("🌐 {$website->name} ({$website->url})");
        $this->line("   Status: {$status}");
        
        if ($result['status_code']) {
            $this->line("   HTTP: {$result['status_code']}");
        }
        
        if ($result['response_time']) {
            $this->line("   Response Time: {$result['response_time']}ms");
        }
        
        if ($result['content_changed']) {
            $this->line("   <fg=blue>Content Changed!</fg=blue>");
        }
        
        if ($result['error_message']) {
            $this->line("   Error: {$result['error_message']}");
        }

        if ($result['ssl_info']) {
            $ssl = json_decode($result['ssl_info'], true);
            $this->line("   SSL: Expires in {$ssl['expires_in_days']} days");
        }

        if ($result['screenshot_path']) {
            $this->line("   📸 Screenshot: storage/app/public/{$result['screenshot_path']}");
        }
    }
}
