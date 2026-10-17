<?php

namespace App\Mcp\Tools;

use App\Jobs\MonitorWebsiteJob;
use App\Models\Website;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Name('run-monitoring')]
#[Description('Queue monitoring checks for one website (by website_id) or for all active websites. Checks run in the background queue worker; use get-website-status or get-monitoring-history afterwards to see the results.')]
#[IsOpenWorld]
class RunMonitoringTool extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'website_id' => 'nullable|integer|exists:websites,id',
            'screenshot' => 'nullable|boolean',
            'timeout' => 'nullable|integer|min:5|max:120',
        ]);

        $websites = ! empty($validated['website_id'])
            ? Website::whereKey($validated['website_id'])->get()
            : Website::where('is_active', true)->get();

        if ($websites->isEmpty()) {
            return Response::error('No websites to monitor.');
        }

        foreach ($websites as $website) {
            MonitorWebsiteJob::dispatch(
                $website,
                (bool) ($validated['screenshot'] ?? false),
                (int) ($validated['timeout'] ?? 30)
            );
        }

        return Response::json([
            'queued' => $websites->count(),
            'websites' => $websites->map(fn (Website $w) => ['id' => $w->id, 'url' => $w->url])->values(),
            'note' => 'Monitoring jobs were queued. Results appear once the queue worker processes them.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'website_id' => $schema->integer()->description('Monitor only this website. Omit to monitor all active websites.'),
            'screenshot' => $schema->boolean()->description('Also capture a screenshot when the site is up (default false).'),
            'timeout' => $schema->integer()->min(5)->max(120)->description('HTTP request timeout in seconds (default 30).'),
        ];
    }
}
