<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-source-snippet')]
#[Description('Show lines of this app\'s source code around a line number, e.g. the file and line from get-error-log, so a fix can be recommended. Read-only and limited to the app\'s own code (app, config, routes, database, resources/views, tests); .env and other secrets are never readable.')]
#[IsReadOnly]
class GetSourceSnippetTool extends Tool
{
    private const ALLOWED_DIRECTORIES = ['app', 'config', 'routes', 'database', 'resources/views', 'tests'];

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'path' => 'required|string|max:500',
            'line' => 'nullable|integer|min:1',
            'context' => 'nullable|integer|min:1|max:200',
        ]);

        $path = $this->resolve($validated['path']);

        if (! $path) {
            return Response::error('File not found or not allowed. Allowed: PHP and Blade files under '.implode(', ', self::ALLOWED_DIRECTORIES).'.');
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $total = count($lines);
        $context = $validated['context'] ?? 20;
        $target = min($validated['line'] ?? 1, max($total, 1));

        $start = isset($validated['line']) ? max(1, $target - $context) : 1;
        $end = isset($validated['line']) ? min($total, $target + $context) : min($total, $context * 2 + 1);

        $snippet = [];
        for ($i = $start; $i <= $end; $i++) {
            $marker = isset($validated['line']) && $i === $target ? '>' : ' ';
            $snippet[] = sprintf('%s%5d | %s', $marker, $i, $lines[$i - 1]);
        }

        return Response::text(sprintf(
            "%s (lines %d-%d of %d)\n\n%s",
            Str::after($path, base_path().DIRECTORY_SEPARATOR),
            $start,
            $end,
            $total,
            implode("\n", $snippet)
        ));
    }

    private function resolve(string $path): ?string
    {
        $relative = ltrim(Str::after(str_replace('\\', '/', $path), str_replace('\\', '/', base_path()).'/'), '/');
        $real = realpath(base_path($relative));

        if (! $real || ! is_file($real)) {
            return null;
        }

        $real = str_replace('\\', '/', $real);
        $base = str_replace('\\', '/', base_path()).'/';

        $allowedDir = collect(self::ALLOWED_DIRECTORIES)->contains(fn ($dir) => str_starts_with($real, $base.$dir.'/'));
        $allowedType = str_ends_with($real, '.php');

        return $allowedDir && $allowedType && ! str_contains(basename($real), '.env') ? $real : null;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('File path relative to the project root, e.g. app/Services/WebsiteMonitoringService.php (absolute paths from the log also work).')->required(),
            'line' => $schema->integer()->min(1)->description('Line to center on (marked with >). Omit to show the start of the file.'),
            'context' => $schema->integer()->min(1)->max(200)->description('Lines to show before and after the line (default 20).'),
        ];
    }
}
