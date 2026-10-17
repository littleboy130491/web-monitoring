<?php

namespace App\Mcp\Tools;

use App\Services\LogReaderService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-error-log')]
#[Description('Read recent errors from the Laravel application log (storage/logs). Returns each error with its exception class, file, line and the app frames of its stack trace, grouped so repeated errors appear once with a count. Secrets are redacted. Use with get-source-snippet to look at the failing code and recommend a fix.')]
#[IsReadOnly]
class GetErrorLogTool extends Tool
{
    public function handle(Request $request, LogReaderService $logs): Response
    {
        $validated = $request->validate([
            'level' => 'nullable|in:'.implode(',', LogReaderService::LEVELS),
            'hours' => 'nullable|integer|min:1|max:8760',
            'search' => 'nullable|string|max:255',
            'group' => 'nullable|boolean',
            'limit' => 'nullable|integer|min:1|max:100',
            'trace_lines' => 'nullable|integer|min:0|max:100',
            'include_vendor_frames' => 'nullable|boolean',
        ]);

        if ($logs->files()->isEmpty()) {
            return Response::text('No log files found in storage/logs.');
        }

        $entries = $logs->entries(
            minLevel: $validated['level'] ?? 'error',
            hours: $validated['hours'] ?? 24,
            search: $validated['search'] ?? null,
            traceLines: $validated['trace_lines'] ?? 15,
            vendorFrames: (bool) ($validated['include_vendor_frames'] ?? false),
        );

        $grouped = $validated['group'] ?? true;
        $items = $grouped ? $logs->group($entries) : $entries;

        return Response::json([
            'filters' => [
                'min_level' => $validated['level'] ?? 'error',
                'hours' => $validated['hours'] ?? 24,
                'search' => $validated['search'] ?? null,
                'grouped' => $grouped,
            ],
            'total_entries' => $entries->count(),
            'returned' => min($items->count(), $validated['limit'] ?? 20),
            'level_counts' => $entries->countBy('level')->all(),
            'log_files' => $logs->files()->map(fn ($f) => basename($f))->all(),
            'errors' => $items->take($validated['limit'] ?? 20)->values(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'level' => $schema->string()->enum(LogReaderService::LEVELS)->description('Lowest log level to include (default error). Use warning to also see warnings.'),
            'hours' => $schema->integer()->min(1)->max(8760)->description('How far back to look, in hours (default 24).'),
            'search' => $schema->string()->description('Only entries containing this text (e.g. a class name, URL or message).'),
            'group' => $schema->boolean()->description('Group identical errors with an occurrence count (default true).'),
            'limit' => $schema->integer()->min(1)->max(100)->description('Maximum errors to return (default 20).'),
            'trace_lines' => $schema->integer()->min(0)->max(100)->description('Stack trace frames per error (default 15).'),
            'include_vendor_frames' => $schema->boolean()->description('Include framework/vendor frames in traces (default false: app code only).'),
        ];
    }
}
