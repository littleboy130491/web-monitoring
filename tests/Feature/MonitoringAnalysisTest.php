<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeMonitoringResultJob;
use App\Mcp\Servers\WebMonitoringServer;
use App\Mcp\Tools\AnalyzeWebsiteTool;
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

    private function withScreenshot(): array
    {
        $relative = 'screenshots/test_'.uniqid().'.jpg';
        $this->screenshot = storage_path('app/public/'.$relative);

        if (! is_dir(dirname($this->screenshot))) {
            mkdir(dirname($this->screenshot), 0755, true);
        }

        file_put_contents($this->screenshot, "\xFF\xD8\xFF\xE0fake-jpeg");

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

    public function test_score_level_parsing(): void
    {
        $this->assertSame(3, JevClient::scoreLevel(['probabilities' => [0.1, 0.1, 0.1, 0.6, 0.1]], 5));
        $this->assertSame(4, JevClient::scoreLevel(['score' => 1.0], 5));
        $this->assertSame(2, JevClient::scoreLevel(['score' => 2], 5));
        $this->assertNull(JevClient::scoreLevel([], 5));
    }
}
