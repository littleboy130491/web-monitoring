<?php

namespace App\Mcp\Tools;

use App\Services\LogReaderService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-failed-jobs')]
#[Description('List queue jobs that failed (e.g. MonitorWebsiteJob, AnalyzeMonitoringResultJob, GenerateMonitoringReportJob) with the exception and the app frames of its stack trace. Secrets are redacted.')]
#[IsReadOnly]
class ListFailedJobsTool extends Tool
{
    public function handle(Request $request, LogReaderService $logs): Response
    {
        $validated = $request->validate([
            'hours' => 'nullable|integer|min:1|max:8760',
            'limit' => 'nullable|integer|min:1|max:100',
            'trace_lines' => 'nullable|integer|min:0|max:100',
        ]);

        $traceLines = $validated['trace_lines'] ?? 10;

        $query = DB::table('failed_jobs')->orderByDesc('failed_at');

        if (! empty($validated['hours'])) {
            $query->where('failed_at', '>=', now()->subHours($validated['hours']));
        }

        $jobs = $query->limit($validated['limit'] ?? 20)->get()->map(function ($job) use ($logs, $traceLines) {
            $payload = json_decode($job->payload, true) ?: [];
            $lines = explode("\n", $logs->redact((string) $job->exception));
            $base = base_path().DIRECTORY_SEPARATOR;

            return [
                'id' => $job->id,
                'uuid' => $job->uuid,
                'job' => $payload['displayName'] ?? null,
                'queue' => $job->queue,
                'attempts' => $payload['attempts'] ?? null,
                'failed_at' => $job->failed_at,
                'exception' => str_replace($base, '', $lines[0]),
                'trace' => collect(array_slice($lines, 1))
                    ->map(fn ($l) => str_replace($base, '', trim($l)))
                    ->filter(fn ($l) => $l !== '' && $l !== 'Stack trace:' && ! str_contains($l, 'vendor/'))
                    ->take($traceLines)
                    ->values()
                    ->all(),
            ];
        });

        return Response::json([
            'total_failed_jobs' => DB::table('failed_jobs')->count(),
            'returned' => $jobs->count(),
            'jobs' => $jobs,
            'note' => 'Retry with `php artisan queue:retry <uuid>` (or `all`) once the cause is fixed.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'hours' => $schema->integer()->min(1)->max(8760)->description('Only jobs that failed within this many hours.'),
            'limit' => $schema->integer()->min(1)->max(100)->description('Maximum jobs to return (default 20).'),
            'trace_lines' => $schema->integer()->min(0)->max(100)->description('App stack frames per job (default 10).'),
        ];
    }
}
