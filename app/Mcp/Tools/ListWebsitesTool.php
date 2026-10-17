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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-websites')]
#[Description('List monitored websites with their latest monitoring result. Supports filtering by search text, active state and latest status.')]
#[IsReadOnly]
class ListWebsitesTool extends Tool
{
    use FormatsMonitoringData;

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'search' => 'nullable|string|max:255',
            'is_active' => 'nullable|boolean',
            'status' => 'nullable|in:up,down,error,warning',
            'limit' => 'nullable|integer|min:1|max:200',
        ]);

        $query = Website::with('latestResult')->orderBy('url');

        if (! empty($validated['search'])) {
            $query->where(function ($q) use ($validated) {
                $q->where('url', 'like', "%{$validated['search']}%")
                    ->orWhere('description', 'like', "%{$validated['search']}%");
            });
        }

        if (array_key_exists('is_active', $validated) && $validated['is_active'] !== null) {
            $query->where('is_active', (bool) $validated['is_active']);
        }

        $websites = $query->get();

        if (! empty($validated['status'])) {
            $websites = $websites->filter(fn (Website $w) => $w->latestResult?->status === $validated['status']);
        }

        $limit = $validated['limit'] ?? 50;

        return Response::json([
            'total' => $websites->count(),
            'websites' => $websites->take($limit)->map(fn (Website $w) => $this->formatWebsite($w))->values(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Filter by text contained in the URL or description.'),
            'is_active' => $schema->boolean()->description('Only return active (true) or inactive (false) websites.'),
            'status' => $schema->string()->enum(['up', 'down', 'error', 'warning'])->description('Only return websites whose latest result has this status.'),
            'limit' => $schema->integer()->min(1)->max(200)->description('Maximum number of websites to return (default 50).'),
        ];
    }
}
