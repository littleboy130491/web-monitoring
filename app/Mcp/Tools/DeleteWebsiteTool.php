<?php

namespace App\Mcp\Tools;

use App\Models\Website;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Name('delete-website')]
#[Description('Permanently delete a monitored website and all of its monitoring results. Prefer update-website with is_active=false to only pause monitoring.')]
#[IsDestructive]
class DeleteWebsiteTool extends Tool
{
    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'id' => 'required|integer',
        ]);

        $website = Website::find($validated['id']);

        if (! $website) {
            return Response::error('Website not found.');
        }

        $url = $website->url;
        $website->monitoringResults()->delete();
        $website->delete();

        return Response::text("Deleted website #{$validated['id']} ({$url}) and its monitoring results.");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('The website ID to delete.')->required(),
        ];
    }
}
