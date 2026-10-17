<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\FormatsMonitoringData;
use App\Models\Website;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('update-website')]
#[Description('Update a monitored website. Only the fields provided are changed; use is_active=false to pause monitoring.')]
#[IsIdempotent]
class UpdateWebsiteTool extends Tool
{
    use FormatsMonitoringData;

    public function handle(Request $request): Response
    {
        $website = Website::find($request->get('id'));

        if (! $website) {
            return Response::error('Website not found.');
        }

        $validated = $request->validate([
            'id' => 'required|integer',
            'url' => ['sometimes', 'url', 'max:255', Rule::unique('websites', 'url')->ignore($website->id)],
            'description' => 'sometimes|nullable|string',
            'is_active' => 'sometimes|boolean',
            'check_interval' => 'sometimes|integer|min:60',
            'headers' => 'sometimes|nullable|array',
        ]);

        $website->update(collect($validated)->except('id')->all());

        return Response::json([
            'message' => 'Website updated.',
            'website' => $this->formatWebsite($website->fresh()),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('The website ID.')->required(),
            'url' => $schema->string()->format('uri')->description('New URL.'),
            'description' => $schema->string()->description('New description.'),
            'is_active' => $schema->boolean()->description('Enable or pause monitoring.'),
            'check_interval' => $schema->integer()->min(60)->description('Check interval in seconds.'),
            'headers' => $schema->object()->description('Custom HTTP headers as a name => value object (replaces existing headers).'),
        ];
    }
}
