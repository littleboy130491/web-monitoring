<?php

namespace Tests\Feature;

use App\Models\Website;
use App\Services\MonitoringPruneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MonitoringPruneCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_groups_old_records_by_website_url(): void
    {
        $website = Website::create([
            'url' => 'https://example.com',
            'description' => null,
            'is_active' => true,
            'check_interval' => 300,
            'headers' => null,
        ]);

        DB::table('monitoring_results')->insert([
            'website_id' => $website->id,
            'status_code' => 200,
            'response_time' => 120,
            'status' => 'up',
            'checked_at' => now()->subDays(40),
            'created_at' => now()->subDays(40),
            'updated_at' => now()->subDays(40),
        ]);

        $stats = app(MonitoringPruneService::class)
            ->getWebsiteStats(now()->subDays(30))
            ->first();

        $this->assertSame('https://example.com', $stats->website_name);
        $this->assertSame(1, $stats->record_count);

        $this->artisan('monitor:prune', ['--days' => 30, '--dry-run' => true])
            ->assertExitCode(0);
    }
}
