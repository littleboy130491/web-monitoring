<?php

namespace Tests\Feature;

use App\Jobs\MonitorWebsiteJob;
use App\Mcp\Resources\StatusOverviewResource;
use App\Mcp\Servers\WebMonitoringServer;
use App\Mcp\Tools\CreateWebsiteTool;
use App\Mcp\Tools\DeleteWebsiteTool;
use App\Mcp\Tools\GetMonitoringHistoryTool;
use App\Mcp\Tools\GetStatusSummaryTool;
use App\Mcp\Tools\GetWebsiteStatusTool;
use App\Mcp\Tools\ListWebsitesTool;
use App\Mcp\Tools\RunMonitoringTool;
use App\Mcp\Tools\UpdateWebsiteTool;
use App\Models\MonitoringResult;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebMonitoringMcpServerTest extends TestCase
{
    use RefreshDatabase;

    private function makeWebsite(string $url, string $status = 'up', array $overrides = []): Website
    {
        $website = Website::create([
            'url' => $url,
            'description' => null,
            'is_active' => true,
            'check_interval' => 3600,
            'headers' => null,
        ]);

        MonitoringResult::create(array_merge([
            'website_id' => $website->id,
            'status_code' => $status === 'up' ? 200 : 500,
            'response_time' => 100,
            'status' => $status,
            'error_message' => $status === 'up' ? null : 'Server error',
            'checked_at' => now(),
        ], $overrides));

        return $website;
    }

    public function test_status_summary_counts_statuses_and_flags_problems(): void
    {
        $this->makeWebsite('https://up.example.com');
        $this->makeWebsite('https://down.example.com', 'down');
        $this->makeWebsite('https://expiring.example.com', 'up', ['domain_days_until_expiry' => 3]);

        WebMonitoringServer::tool(GetStatusSummaryTool::class)
            ->assertOk()
            ->assertSee(['"up":2', '"down":1', 'down.example.com', 'domain expires in 3 days'])
            ->assertDontSee('up.example.com');
    }

    public function test_list_websites_filters_by_latest_status(): void
    {
        $this->makeWebsite('https://up.example.com');
        $this->makeWebsite('https://down.example.com', 'down');

        WebMonitoringServer::tool(ListWebsitesTool::class, ['status' => 'down'])
            ->assertOk()
            ->assertSee('down.example.com')
            ->assertDontSee('up.example.com');
    }

    public function test_get_website_status_by_url_and_unknown_website(): void
    {
        $this->makeWebsite('https://example.com', 'up', ['scan_results' => ['broken_assets' => [['type' => 'css', 'url' => 'https://example.com/a.css']]]]);

        WebMonitoringServer::tool(GetWebsiteStatusTool::class, ['url' => 'https://example.com'])
            ->assertOk()
            ->assertSee(['"broken_assets_count":1', 'a.css']);

        WebMonitoringServer::tool(GetWebsiteStatusTool::class, ['id' => 999])
            ->assertHasErrors(['Website not found.']);
    }

    public function test_monitoring_history_reports_uptime(): void
    {
        $website = $this->makeWebsite('https://example.com');
        MonitoringResult::create([
            'website_id' => $website->id,
            'status' => 'down',
            'checked_at' => now()->subHour(),
        ]);

        WebMonitoringServer::tool(GetMonitoringHistoryTool::class, ['website_id' => $website->id])
            ->assertOk()
            ->assertSee(['"count":2', '"uptime_percent":50']);
    }

    public function test_run_monitoring_queues_jobs_for_active_websites(): void
    {
        Queue::fake();

        $this->makeWebsite('https://a.example.com');
        $this->makeWebsite('https://b.example.com');
        Website::create(['url' => 'https://inactive.example.com', 'is_active' => false, 'check_interval' => 3600]);

        WebMonitoringServer::tool(RunMonitoringTool::class, ['screenshot' => true])
            ->assertOk()
            ->assertSee('"queued":2');

        Queue::assertPushed(MonitorWebsiteJob::class, 2);
        Queue::assertPushed(MonitorWebsiteJob::class, fn (MonitorWebsiteJob $job) => $job->takeScreenshot);
    }

    public function test_create_update_and_delete_website(): void
    {
        WebMonitoringServer::tool(CreateWebsiteTool::class, ['url' => 'https://new.example.com'])
            ->assertOk()
            ->assertSee('Website created.');

        $website = Website::where('url', 'https://new.example.com')->firstOrFail();
        $this->assertTrue($website->is_active);
        $this->assertSame(3600, $website->check_interval);

        WebMonitoringServer::tool(CreateWebsiteTool::class, ['url' => 'https://new.example.com'])
            ->assertHasErrors(['already being monitored']);

        WebMonitoringServer::tool(UpdateWebsiteTool::class, ['id' => $website->id, 'is_active' => false])
            ->assertOk();
        $this->assertFalse($website->fresh()->is_active);

        WebMonitoringServer::tool(DeleteWebsiteTool::class, ['id' => $website->id])
            ->assertOk();
        $this->assertNull(Website::find($website->id));
    }

    public function test_status_overview_resource(): void
    {
        $this->makeWebsite('https://down.example.com', 'down');

        WebMonitoringServer::resource(StatusOverviewResource::class)
            ->assertOk()
            ->assertSee('down.example.com');
    }

    public function test_http_endpoint_requires_token(): void
    {
        $payload = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'];

        config(['monitoring.mcp_token' => null]);
        $this->postJson('/mcp', $payload)->assertNotFound();

        config(['monitoring.mcp_token' => 'secret']);
        $this->postJson('/mcp', $payload)->assertUnauthorized();
        $this->postJson('/mcp', $payload, ['Authorization' => 'Bearer secret'])->assertOk();
    }
}
