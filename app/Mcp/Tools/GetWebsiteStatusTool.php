<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\FormatsMonitoringData;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-website-status')]
#[Description('Get a website\'s configuration and full details of its latest monitoring result (SSL info, domain expiry, content scan, broken assets, screenshot). Identify the website by id or url.')]
#[IsReadOnly]
class GetWebsiteStatusTool extends Tool
{
    use FormatsMonitoringData;

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'id' => 'required_without:url|nullable|integer',
            'url' => 'required_without:id|nullable|string',
        ]);

        $website = $this->findWebsite($validated['id'] ?? null, $validated['url'] ?? null);

        if (! $website) {
            return Response::error('Website not found.');
        }

        $data = $this->formatWebsite($website);
        $data['latest_result'] = $website->latestResult
            ? $this->formatResult($website->latestResult, detailed: true)
            : null;

        return Response::json($data);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('The website ID.'),
            'url' => $schema->string()->description('The website URL (used when id is not given).'),
        ];
    }
}
