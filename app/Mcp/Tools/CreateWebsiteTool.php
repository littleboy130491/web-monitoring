<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\FormatsMonitoringData;
use App\Models\Website;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('create-website')]
#[Description('Add a new website to monitor.')]
class CreateWebsiteTool extends Tool
{
    use FormatsMonitoringData;

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'url' => 'required|url|max:255|unique:websites,url',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
            'check_interval' => 'nullable|integer|min:60',
            'headers' => 'nullable|array',
        ], [
            'url.unique' => 'A website with this URL is already being monitored.',
        ]);

        $website = Website::create([
            'url' => $validated['url'],
            'description' => $validated['description'] ?? null,
            'is_active' => $validated['is_active'] ?? true,
            'check_interval' => $validated['check_interval'] ?? 3600,
            'headers' => $validated['headers'] ?? null,
        ]);

        return Response::json([
            'message' => 'Website created.',
            'website' => $this->formatWebsite($website),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()->format('uri')->description('The full URL to monitor, e.g. https://example.com.')->required(),
            'description' => $schema->string()->description('Optional description.'),
            'is_active' => $schema->boolean()->description('Whether the website is actively monitored (default true).'),
            'check_interval' => $schema->integer()->min(60)->description('Check interval in seconds (default 3600).'),
            'headers' => $schema->object()->description('Optional custom HTTP headers to send, as a name => value object.'),
        ];
    }
}
