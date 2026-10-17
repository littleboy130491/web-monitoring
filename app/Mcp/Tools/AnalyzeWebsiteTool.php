<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\FormatsMonitoringData;
use App\Models\MonitoringResult;
use App\Services\Ai\MonitoringAnalysisService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Throwable;

#[Name('analyze-website')]
#[Description('Run AI analysis (Jev) on a website\'s latest monitoring result, or on a specific result: severity, urgency, issue category, and whether the page looks broken or hacked (screenshot included when available). Takes a few seconds.')]
#[IsOpenWorld]
class AnalyzeWebsiteTool extends Tool
{
    use FormatsMonitoringData;

    public function handle(Request $request, MonitoringAnalysisService $analysis): Response
    {
        $validated = $request->validate([
            'website_id' => 'required_without:result_id|nullable|integer',
            'result_id' => 'required_without:website_id|nullable|integer',
        ]);

        if (! $analysis->isEnabled()) {
            return Response::error('AI analysis is not configured. Set JEV_API_KEY or OPENROUTER_API_KEY.');
        }

        $result = ! empty($validated['result_id'])
            ? MonitoringResult::find($validated['result_id'])
            : MonitoringResult::where('website_id', $validated['website_id'])->latest('id')->first();

        if (! $result) {
            return Response::error('Monitoring result not found.');
        }

        try {
            $outcome = $analysis->analyze($result);
        } catch (Throwable $e) {
            return Response::error('AI analysis failed: '.$e->getMessage());
        }

        return Response::json([
            'website_id' => $result->website_id,
            'url' => $result->website->url,
            'result_id' => $result->id,
            'analysis' => collect($outcome)->except('raw_answers')->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'website_id' => $schema->integer()->description('Analyze the latest result of this website.'),
            'result_id' => $schema->integer()->description('Analyze this specific monitoring result instead.'),
        ];
    }
}
