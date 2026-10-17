<?php

namespace Tests\Feature;

use App\Mcp\Prompts\DiagnoseErrorsPrompt;
use App\Mcp\Servers\WebMonitoringServer;
use App\Mcp\Tools\GetErrorLogTool;
use App\Mcp\Tools\GetSourceSnippetTool;
use App\Mcp\Tools\ListFailedJobsTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class McpErrorLogToolsTest extends TestCase
{
    use RefreshDatabase;

    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = storage_path('logs/mcp-test-'.uniqid().'.log');
        $base = base_path();
        $now = now()->format('Y-m-d H:i:s');
        $old = now()->subDays(3)->format('Y-m-d H:i:s');

        $exception = fn (string $id) => "[{$now}] local.ERROR: Undefined array key \"expires_in_days\" ZZTOKEN {\"exception\":\"[object] (ErrorException(code: 0): Undefined array key \\\"expires_in_days\\\" at {$base}/app/Console/Commands/MonitorWebsites.php:122)\n[stacktrace]\n#0 {$base}/vendor/laravel/framework/src/Illuminate/Foundation/Bootstrap/HandleExceptions.php(256): handleError()\n#1 {$base}/app/Console/Commands/MonitorWebsites.php(122): {closure}()\n#2 {$base}/app/Console/Commands/MonitorWebsites.php(58): displayResult()\n\"} \n";

        file_put_contents($this->logFile,
            "[{$old}] local.ERROR: Old error ZZTOKEN\n".
            $exception('a').
            "[{$now}] local.INFO: Monitoring started ZZTOKEN\n".
            $exception('b').
            "[{$now}] local.WARNING: Deep scan failed for https://example.com ZZTOKEN\n".
            "[{$now}] local.ERROR: Jev request failed with Bearer abcdefghijklmnop1234 and key sk-or-v1-0123456789abcdefghij ZZTOKEN\n"
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);

        parent::tearDown();
    }

    public function test_error_log_groups_errors_and_redacts_secrets(): void
    {
        WebMonitoringServer::tool(GetErrorLogTool::class, ['search' => 'ZZTOKEN'])
            ->assertOk()
            ->assertSee([
                '"total_entries":3',
                '"occurrences":2',
                '"exception":"ErrorException"',
                '"file":"app/Console/Commands/MonitorWebsites.php"',
                '"line":122',
                'app/Console/Commands/MonitorWebsites.php(58): displayResult()',
                'Bearer [REDACTED]',
            ])
            ->assertDontSee(['abcdefghijklmnop1234', 'sk-or-v1-0123456789abcdefghij', 'Old error', 'Monitoring started', 'HandleExceptions.php']);

        WebMonitoringServer::tool(GetErrorLogTool::class, ['search' => 'ZZTOKEN', 'level' => 'warning', 'hours' => 168, 'group' => false])
            ->assertOk()
            ->assertSee(['"total_entries":5', 'Deep scan failed', 'Old error']);
    }

    public function test_failed_jobs_are_listed_with_app_frames(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => 'job-uuid-1',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\AnalyzeMonitoringResultJob', 'attempts' => 3]),
            'exception' => 'App\\Services\\Ai\\AiAnalysisException: Jev request failed (HTTP 401) in '.base_path()."/app/Services/Ai/JevClient.php:105\nStack trace:\n#0 ".base_path()."/vendor/x.php(1): y()\n#1 ".base_path().'/app/Jobs/AnalyzeMonitoringResultJob.php(33): decide()',
            'failed_at' => now(),
        ]);

        WebMonitoringServer::tool(ListFailedJobsTool::class)
            ->assertOk()
            ->assertSee(['AnalyzeMonitoringResultJob', 'HTTP 401', 'app/Services/Ai/JevClient.php:105', 'app/Jobs/AnalyzeMonitoringResultJob.php(33)'])
            ->assertDontSee('vendor/x.php');
    }

    public function test_source_snippet_shows_code_and_blocks_secrets(): void
    {
        WebMonitoringServer::tool(GetSourceSnippetTool::class, ['path' => 'app/Models/Website.php', 'line' => 9, 'context' => 2])
            ->assertOk()
            ->assertSee(['app/Models/Website.php (lines 7-11', '>    9 |']);

        WebMonitoringServer::tool(GetSourceSnippetTool::class, ['path' => base_path('app/Models/Website.php')])
            ->assertOk();

        foreach (['.env', '.env.example', 'app/../.env', 'vendor/autoload.php', 'storage/logs/laravel.log', 'composer.json', 'app/Models/Missing.php'] as $path) {
            WebMonitoringServer::tool(GetSourceSnippetTool::class, ['path' => $path])
                ->assertHasErrors(['not allowed']);
        }
    }

    public function test_diagnose_errors_prompt(): void
    {
        WebMonitoringServer::prompt(DiagnoseErrorsPrompt::class, ['hours' => 6, 'focus' => 'JevClient'])
            ->assertOk()
            ->assertSee(['get-error-log with hours=6', 'search="JevClient"', 'list-failed-jobs', 'get-source-snippet']);
    }
}
