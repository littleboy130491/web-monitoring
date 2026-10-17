<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeMonitoringResultJob;
use App\Mcp\Servers\WebMonitoringServer;
use App\Mcp\Tools\AnalyzeWebsiteTool;
use App\Mcp\Tools\GetAiAnalysisTool;
use App\Mcp\Tools\ListAiFindingsTool;
use App\Models\MonitoringResult;
use App\Models\User;
use App\Models\Website;
use App\Services\Ai\JevClient;
use App\Services\Ai\MonitoringAnalysisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MonitoringAnalysisTest extends TestCase
{
    use RefreshDatabase;

    private ?string $screenshot = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.enabled' => true,
            'ai.decision_provider' => 'auto',
            'ai.jev.api_key' => null,
            'ai.jev.images' => false,
            'ai.openrouter.api_key' => null,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->screenshot && is_file($this->screenshot)) {
            unlink($this->screenshot);
        }

        parent::tearDown();
    }

    private function makeResult(array $overrides = []): MonitoringResult
    {
        $website = Website::create([
            'url' => 'https://example.com',
            'description' => 'Bakery website',
            'is_active' => true,
            'check_interval' => 3600,
        ]);

        return MonitoringResult::create(array_merge([
            'website_id' => $website->id,
            'status_code' => 200,
            'response_time' => 300,
            'status' => 'up',
            'checked_at' => now(),
        ], $overrides));
    }

    private function withScreenshot(int $width = 1280, int $height = 720): array
    {
        $relative = 'screenshots/test_'.uniqid().'.jpg';
        $this->screenshot = storage_path('app/public/'.$relative);

        if (! is_dir(dirname($this->screenshot))) {
            mkdir(dirname($this->screenshot), 0755, true);
        }

        $image = imagecreatetruecolor($width, $height);
        imagejpeg($image, $this->screenshot, 70);
        imagedestroy($image);

        return ['screenshot_path' => $relative];
    }

    private function jevAnswers(int $severityLevel = 0, float $compromised = 0.02, float $broken = 0.05): array
    {
        $probabilities = array_fill(0, 5, 0.0);
        $probabilities[$severityLevel] = 1.0;

        return ['answers' => [
            'severity' => ['type' => 'score', 'score' => $severityLevel / 4, 'probabilities' => $probabilities, 'confidence' => 0.9],
            'urgency' => ['type' => 'choice', 'choice' => $severityLevel >= 3 ? 'today' : 'none', 'confidence' => 0.8],
            'category' => ['type' => 'choice', 'choice' => $severityLevel ? 'broken_page' : 'healthy', 'confidence' => 0.8],
            'page_broken' => ['type' => 'noul', 'noul' => $broken],
            'compromised' => ['type' => 'noul', 'noul' => $compromised],
        ]];
    }

    public function test_disabled_without_api_keys(): void
    {
        $this->assertFalse(app(MonitoringAnalysisService::class)->isEnabled());

        $this->artisan('monitor:analyze')->assertExitCode(1);
    }

    public function test_analyzes_with_jev_directly_and_stores_the_outcome(): void
    {
        config(['ai.jev.api_key' => 'jev-key']);
        Http::fake(['api.typesafe.ai/*' => Http::response($this->jevAnswers(3, broken: 0.9))]);

        $result = $this->makeResult();
        $outcome = app(MonitoringAnalysisService::class)->analyze($result);

        $this->assertSame('high', $outcome['severity']);
        $this->assertSame('today', $outcome['urgency']);
        $this->assertTrue($outcome['page_broken']);
        $this->assertFalse($outcome['compromised']);
        $this->assertSame('jev', $outcome['provider']);

        $result->refresh();
        $this->assertSame('high', $result->ai_severity);
        $this->assertNotNull($result->ai_analyzed_at);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.typesafe.ai/v1/systemone'
                && $request->hasHeader('Authorization', 'Bearer jev-key')
                && $request['model'] === 'jev-latest'
                && $request['questions']['severity']['type'] === 'score'
                && $request['state']['website']['url'] === 'https://example.com'
                && ! isset($request['assets']);
        });
    }

    public function test_openrouter_reads_the_screenshot_and_a_compromise_is_critical(): void
    {
        config(['ai.openrouter.api_key' => 'or-key']);
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => json_encode([
                'page_broken' => false,
                'broken_reasons' => [],
                'compromised' => true,
                'compromise_indicators' => ['casino spam banner'],
                'description' => 'Bakery homepage with a casino banner.',
                'confidence' => 0.85,
            ])]]]]),
            'openrouter.ai/api/alpha/decisions' => Http::response($this->jevAnswers(1)),
        ]);

        $result = $this->makeResult($this->withScreenshot());
        $outcome = app(MonitoringAnalysisService::class)->analyze($result);

        $this->assertSame('critical', $outcome['severity']);
        $this->assertSame('immediate', $outcome['urgency']);
        $this->assertTrue($outcome['compromised']);
        $this->assertSame('vision_model', $outcome['screenshot_source']);
        $this->assertStringContainsString('casino spam banner', $outcome['summary']);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/chat/completions')
            && str_starts_with($r['messages'][1]['content'][1]['image_url']['url'], 'data:'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/alpha/decisions')
            && $r['model'] === 'typesafe/jev-1.13'
            && $r['state']['screenshot_review']['compromised'] === true);
    }

    public function test_jev_image_input_uses_documented_asset_format(): void
    {
        config(['ai.jev.api_key' => 'jev-key', 'ai.jev.images' => true, 'ai.jev.model' => 'jev-latest']);
        Http::fake(['api.typesafe.ai/*' => Http::response(['model' => 'jev-1.14.0'] + $this->jevAnswers(0))]);

        $result = $this->makeResult($this->withScreenshot());
        $outcome = app(MonitoringAnalysisService::class)->analyze($result);

        $this->assertSame('jev', $outcome['screenshot_source']);
        $this->assertSame('jev-1.14.0', $outcome['model']);
        $this->assertSame([], $outcome['errors']);

        Http::assertSent(function (Request $r) {
            $asset = $r['assets']['screenshot-image'] ?? [];

            return $r->hasHeader('TypeSafe-Danger-Zone-Images', 'true')
                && $asset['type'] === 'image'
                && $asset['format'] === 'jpg'
                && base64_decode($asset['data_base64'], true) !== false
                && ! str_starts_with($asset['data_base64'], 'data:');
        });
    }

    public function test_jev_image_input_skips_images_outside_size_limits(): void
    {
        config(['ai.jev.api_key' => 'jev-key', 'ai.jev.images' => true]);
        Http::fake(['api.typesafe.ai/*' => Http::response($this->jevAnswers(0))]);

        $result = $this->makeResult($this->withScreenshot(2000, 720));
        $outcome = app(MonitoringAnalysisService::class)->analyze($result);

        $this->assertNull($outcome['screenshot_source']);
        $this->assertStringContainsString('256-1568 px', $outcome['errors'][0]);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => ! isset($r['assets']));
    }

    public function test_jev_image_input_sends_assets_and_falls_back_to_text_when_rejected(): void
    {
        config(['ai.jev.api_key' => 'jev-key', 'ai.jev.images' => true]);
        Http::fake(['api.typesafe.ai/*' => Http::sequence()
            ->push(['error' => 'images not supported'], 400)
            ->push($this->jevAnswers(0))]);

        $result = $this->makeResult($this->withScreenshot());
        $outcome = app(MonitoringAnalysisService::class)->analyze($result);

        $this->assertSame('none', $outcome['severity']);
        $this->assertNull($outcome['screenshot_source']);
        $this->assertStringContainsString('Jev image input failed', $outcome['errors'][0]);

        $sent = Http::recorded();
        $this->assertCount(2, $sent);
        [$first] = $sent[0];
        $this->assertTrue($first->hasHeader('TypeSafe-Danger-Zone-Images', 'true'));
        $this->assertArrayHasKey('screenshot-image', $first['assets']);
        $this->assertSame('screenshot-image', $first['state']['screenshot']);
        [$second] = $sent[1];
        $this->assertArrayNotHasKey('assets', $second->data());
    }

    public function test_job_notifies_admins_when_severity_reaches_threshold(): void
    {
        config(['ai.jev.api_key' => 'jev-key', 'ai.notify_min_severity' => 'high']);
        Http::fake(['api.typesafe.ai/*' => Http::response($this->jevAnswers(4))]);
        $user = User::factory()->create();

        (new AnalyzeMonitoringResultJob($this->makeResult(['status' => 'down'])))
            ->handle(app(MonitoringAnalysisService::class));

        $this->assertSame(1, $user->notifications()->count());
        $this->assertStringContainsString('Critical', $user->notifications()->first()->data['title']);
    }

    public function test_job_stays_quiet_below_threshold(): void
    {
        config(['ai.jev.api_key' => 'jev-key']);
        Http::fake(['api.typesafe.ai/*' => Http::response($this->jevAnswers(1))]);
        $user = User::factory()->create();

        (new AnalyzeMonitoringResultJob($this->makeResult()))->handle(app(MonitoringAnalysisService::class));

        $this->assertSame(0, $user->notifications()->count());
    }

    public function test_analyze_command_and_mcp_tool(): void
    {
        config(['ai.jev.api_key' => 'jev-key']);
        Http::fake(['api.typesafe.ai/*' => Http::response($this->jevAnswers(2))]);
        $result = $this->makeResult();

        $this->artisan('monitor:analyze', ['--id' => $result->website_id])
            ->expectsOutputToContain('[medium, none]')
            ->assertExitCode(0);

        WebMonitoringServer::tool(AnalyzeWebsiteTool::class, ['website_id' => $result->website_id])
            ->assertOk()
            ->assertSee(['"severity":"medium"', 'example.com']);
    }

    public function test_mcp_reads_stored_analyses(): void
    {
        config(['ai.jev.api_key' => 'jev-key']);
        $hacked = $this->makeResult();
        $hacked->update([
            'ai_severity' => 'critical',
            'ai_urgency' => 'immediate',
            'ai_analyzed_at' => now(),
            'ai_analysis' => [
                'severity' => 'critical',
                'compromised' => true,
                'page_broken' => false,
                'summary' => 'Critical severity - compromised - casino spam.',
                'raw_answers' => ['compromised' => ['type' => 'noul', 'noul' => 0.94]],
            ],
        ]);

        $healthySite = Website::create(['url' => 'https://ok.example.com', 'is_active' => true, 'check_interval' => 3600]);
        MonitoringResult::create([
            'website_id' => $healthySite->id,
            'status' => 'up',
            'checked_at' => now(),
            'ai_severity' => 'none',
            'ai_urgency' => 'none',
            'ai_analyzed_at' => now(),
            'ai_analysis' => ['severity' => 'none', 'compromised' => false, 'page_broken' => false, 'summary' => 'None severity.'],
        ]);

        WebMonitoringServer::tool(ListAiFindingsTool::class)
            ->assertOk()
            ->assertSee(['"count":1', 'casino spam', '"websites_with_analysis":2'])
            ->assertDontSee('ok.example.com');

        WebMonitoringServer::tool(ListAiFindingsTool::class, ['min_severity' => 'none'])
            ->assertOk()
            ->assertSee(['"count":2', 'ok.example.com']);

        WebMonitoringServer::tool(GetAiAnalysisTool::class, ['url' => 'https://example.com', 'include_raw' => true])
            ->assertOk()
            ->assertSee(['"severity":"critical"', '"latest_result_is_analyzed":true', '"noul":0.94']);

        WebMonitoringServer::tool(GetAiAnalysisTool::class, ['id' => $hacked->website_id])
            ->assertOk()
            ->assertDontSee('raw_answers');

        $unanalyzed = MonitoringResult::create(['website_id' => $healthySite->id, 'status' => 'up', 'checked_at' => now()]);

        WebMonitoringServer::tool(GetAiAnalysisTool::class, ['result_id' => $unanalyzed->id])
            ->assertHasErrors(['has not been analyzed yet']);

        WebMonitoringServer::tool(GetAiAnalysisTool::class, ['id' => $healthySite->id])
            ->assertOk()
            ->assertSee('"latest_result_is_analyzed":false');
    }

    public function test_reachable_page_with_stale_elementor_css_is_a_broken_layout(): void
    {
        config(['ai.jev.api_key' => 'jev-key']);

        // Jev rates it low, but is confident the layout is broken
        $answers = $this->jevAnswers(1);
        $answers['answers']['layout_broken'] = ['type' => 'noul', 'noul' => 0.87];
        $answers['answers']['category']['choice'] = 'broken_assets';
        Http::fake(['api.typesafe.ai/*' => Http::response($answers)]);
        $user = User::factory()->create();

        $result = $this->makeResult(['scan_results' => [
            'page_builder' => 'elementor',
            'pages' => [],
            'broken_assets' => [
                ['type' => 'css', 'url' => 'https://example.com/wp-content/uploads/elementor/css/post-42.css', 'status' => 404],
                ['type' => 'css', 'url' => 'https://example.com/wp-content/uploads/elementor/css/global.css', 'status' => 404],
            ],
            'any_significant_change' => false,
        ]]);

        $outcome = app(MonitoringAnalysisService::class)->analyze($result);

        $this->assertTrue($outcome['layout_broken']);
        $this->assertSame('high', $outcome['severity']);
        $this->assertSame('today', $outcome['urgency']);
        $this->assertSame('broken_layout', $outcome['category']);
        $this->assertStringContainsString('Stale Elementor CSS cache', $outcome['likely_cause']);
        $this->assertStringContainsString('Regenerate CSS & Data', $outcome['suggested_fix']);
        $this->assertSame(2, $outcome['layout_signals']['broken_stylesheets']);
        $this->assertStringContainsString('layout is broken', $outcome['summary']);

        Http::assertSent(fn (Request $r) => $r['questions']['layout_broken']['type'] === 'noul'
            && $r['state']['layout_signals']['page_builder'] === 'elementor'
            && count($r['state']['layout_signals']['broken_page_builder_css']) === 2
            && $r['state']['layout_signals']['page_reachable'] === true);

        (new AnalyzeMonitoringResultJob($result))->handle(app(MonitoringAnalysisService::class));
        $this->assertSame('AI: Page Layout Broken', $user->notifications()->first()->data['title']);

        WebMonitoringServer::tool(ListAiFindingsTool::class, ['broken_only' => true])
            ->assertOk()
            ->assertSee(['"layout_broken":true', 'Regenerate CSS']);
    }

    public function test_vision_layout_findings_mark_layout_broken(): void
    {
        config(['ai.openrouter.api_key' => 'or-key']);
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => '```json'."\n".json_encode([
                'page_broken' => false,
                'layout_broken' => true,
                'layout_issues' => ['sections stacked in one unstyled column', 'menu shown as a plain list'],
                'compromised' => false,
                'description' => 'Homepage text loads without styling.',
            ])."\n```"]]]]),
            'openrouter.ai/api/alpha/decisions' => Http::response($this->jevAnswers(0)),
        ]);

        $outcome = app(MonitoringAnalysisService::class)->analyze($this->makeResult($this->withScreenshot()));

        $this->assertTrue($outcome['layout_broken']);
        $this->assertSame('high', $outcome['severity']);
        $this->assertStringContainsString('menu shown as a plain list', $outcome['summary']);
        $this->assertNull($outcome['suggested_fix']);
    }

    public function test_page_builder_css_is_recognised(): void
    {
        $service = app(\App\Services\WebsiteMonitoringService::class);

        $this->assertSame('elementor', $service->pageBuilderForUrl('https://x.test/wp-content/uploads/elementor/css/post-7.css?ver=1'));
        $this->assertSame('divi', $service->pageBuilderForUrl('https://x.test/wp-content/et-cache/7/et-core.css'));
        $this->assertNull($service->pageBuilderForUrl('https://x.test/wp-content/themes/astra/style.css'));
    }

    public function test_score_level_parsing(): void
    {
        $this->assertSame(3, JevClient::scoreLevel(['probabilities' => [0.1, 0.1, 0.1, 0.6, 0.1]], 5));
        $this->assertSame(4, JevClient::scoreLevel(['score' => 1.0], 5));
        $this->assertSame(2, JevClient::scoreLevel(['score' => 2], 5));
        $this->assertNull(JevClient::scoreLevel([], 5));
    }
}
