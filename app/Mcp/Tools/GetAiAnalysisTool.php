<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\FormatsMonitoringData;
use App\Models\MonitoringResult;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-ai-analysis')]
#[Description('Get the stored AI (Jev) analysis for a website: severity, urgency, issue category, whether the page looks broken or hacked, the summary, the screenshot review and any warnings. Returns the most recent analyzed result by default, or several recent ones with `limit`. Identify the website by id or url, or pass a result_id. Does not run a new analysis; use analyze-website for that.')]
#[IsReadOnly]
class GetAiAnalysisTool extends Tool
{
    use FormatsMonitoringData;

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'id' => 'nullable|integer',
            'url' => 'nullable|string',
            'result_id' => 'nullable|integer',
            'limit' => 'nullable|integer|min:1|max:20',
            'include_raw' => 'nullable|boolean',
        ]);

        $includeRaw = (bool) ($validated['include_raw'] ?? false);

        if (! empty($validated['result_id'])) {
            $result = MonitoringResult::with('website')->find($validated['result_id']);

            if (! $result) {
                return Response::error('Monitoring result not found.');
            }

            if (! $result->ai_analyzed_at) {
                return Response::error("Result #{$result->id} has not been analyzed yet. Use analyze-website with result_id to analyze it.");
            }

            return Response::json($this->formatAnalysis($result, $includeRaw));
        }

        $website = $this->findWebsite($validated['id'] ?? null, $validated['url'] ?? null);

        if (! $website) {
            return Response::error('Website not found. Pass id, url or result_id.');
        }

        $results = $website->monitoringResults()
            ->with('website')
            ->whereNotNull('ai_analyzed_at')
            ->latest('id')
            ->limit($validated['limit'] ?? 1)
            ->get();

        if ($results->isEmpty()) {
            return Response::error("{$website->url} has no AI analysis yet. Use analyze-website to run one.");
        }

        $latestResultId = $website->monitoringResults()->max('id');

        return Response::json([
            'website_id' => $website->id,
            'url' => $website->url,
            'latest_result_is_analyzed' => $results->first()->id === $latestResultId,
            'analyses' => $results->map(fn (MonitoringResult $r) => $this->formatAnalysis($r, $includeRaw))->values(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('The website ID.'),
            'url' => $schema->string()->description('The website URL (used when id is not given).'),
            'result_id' => $schema->integer()->description('A specific monitoring result ID instead of a website.'),
            'limit' => $schema->integer()->min(1)->max(20)->description('Number of recent analyses to return for the website (default 1).'),
            'include_raw' => $schema->boolean()->description('Also include Jev\'s raw answers with full probabilities (default false).'),
        ];
    }
}
