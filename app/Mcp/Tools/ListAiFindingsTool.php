<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\FormatsMonitoringData;
use App\Models\MonitoringResult;
use App\Models\Website;
use App\Services\Ai\MonitoringAnalysisService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-ai-findings')]
#[Description('List AI (Jev) analysis findings across all websites, most severe first. By default returns each website\'s latest analysis at or above medium severity, plus anything flagged as hacked or broken. Use this to answer "what did the AI find?" or "which sites need attention?".')]
#[IsReadOnly]
class ListAiFindingsTool extends Tool
{
    use FormatsMonitoringData;

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'min_severity' => 'nullable|in:'.implode(',', MonitoringAnalysisService::SEVERITIES),
            'compromised_only' => 'nullable|boolean',
            'broken_only' => 'nullable|boolean',
            'hours' => 'nullable|integer|min:1|max:8760',
            'latest_only' => 'nullable|boolean',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $minRank = MonitoringAnalysisService::severityRank($validated['min_severity'] ?? 'medium');
        $latestOnly = $validated['latest_only'] ?? true;

        $query = MonitoringResult::with('website')->whereNotNull('ai_analyzed_at');

        if (! empty($validated['hours'])) {
            $query->where('ai_analyzed_at', '>=', now()->subHours($validated['hours']));
        }

        if ($latestOnly) {
            // Each website's most recent analyzed result
            $query->whereIn('id', MonitoringResult::selectRaw('max(id)')
                ->whereNotNull('ai_analyzed_at')
                ->groupBy('website_id'));
        }

        $results = $query->latest('id')->limit(1000)->get()->filter(function (MonitoringResult $r) use ($validated, $minRank) {
            $analysis = $r->ai_analysis ?? [];
            $compromised = (bool) ($analysis['compromised'] ?? false);
            $broken = (bool) ($analysis['page_broken'] ?? false);

            if (! empty($validated['compromised_only'])) {
                return $compromised;
            }

            if (! empty($validated['broken_only'])) {
                return $broken;
            }

            return $compromised || $broken
                || MonitoringAnalysisService::severityRank($r->ai_severity) >= $minRank;
        });

        $sorted = $results->sortBy([
            fn ($a, $b) => MonitoringAnalysisService::severityRank($b->ai_severity) <=> MonitoringAnalysisService::severityRank($a->ai_severity),
            fn ($a, $b) => $b->ai_analyzed_at <=> $a->ai_analyzed_at,
        ])->values();

        $analyzedSites = MonitoringResult::whereNotNull('ai_analyzed_at')->distinct()->count('website_id');

        return Response::json([
            'filters' => [
                'min_severity' => MonitoringAnalysisService::SEVERITIES[$minRank],
                'latest_only' => $latestOnly,
                'hours' => $validated['hours'] ?? null,
            ],
            'websites_with_analysis' => $analyzedSites,
            'websites_without_analysis' => max(0, Website::where('is_active', true)->count() - $analyzedSites),
            'count' => $sorted->count(),
            'severity_counts' => collect(MonitoringAnalysisService::SEVERITIES)
                ->mapWithKeys(fn ($s) => [$s => $sorted->where('ai_severity', $s)->count()])
                ->filter()
                ->all(),
            'findings' => $sorted->take($validated['limit'] ?? 25)
                ->map(fn (MonitoringResult $r) => $this->formatAnalysis($r))
                ->values(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'min_severity' => $schema->string()->enum(MonitoringAnalysisService::SEVERITIES)->description('Lowest severity to include (default medium). Hacked or broken findings are always included.'),
            'compromised_only' => $schema->boolean()->description('Only findings where the site looks hacked.'),
            'broken_only' => $schema->boolean()->description('Only findings where the page looks broken.'),
            'hours' => $schema->integer()->min(1)->max(8760)->description('Only analyses made within this many hours.'),
            'latest_only' => $schema->boolean()->description('Only each website\'s most recent analysis (default true). Set false to include older analyses.'),
            'limit' => $schema->integer()->min(1)->max(100)->description('Maximum findings to return (default 25).'),
        ];
    }
}
