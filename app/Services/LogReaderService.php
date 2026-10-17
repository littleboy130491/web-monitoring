<?php

namespace App\Services;

use Illuminate\Support\Collection;

/**
 * Reads and parses Laravel log files (storage/logs/laravel*.log) so errors can
 * be inspected through MCP. Known secrets are redacted from everything returned.
 */
class LogReaderService
{
    public const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    /** Bytes read from the end of each log file */
    private const MAX_BYTES_PER_FILE = 5 * 1024 * 1024;

    private const ENTRY_PATTERN = '/^\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:[+-]\d{2}:?\d{2}|Z)?)\] (\w+)\.(\w+): /m';

    /**
     * @return Collection<int, string> Log file paths, newest first
     */
    public function files(): Collection
    {
        return collect(glob(storage_path('logs/*.log')) ?: [])
            ->sortByDesc(fn (string $path) => filemtime($path))
            ->values();
    }

    /**
     * Parsed log entries, newest first.
     *
     * @return Collection<int, array>
     */
    public function entries(string $minLevel = 'error', ?int $hours = 24, ?string $search = null, int $traceLines = 15, bool $vendorFrames = false): Collection
    {
        $minRank = array_search(strtolower($minLevel), self::LEVELS, true) ?: 0;
        $since = $hours ? now()->subHours($hours) : null;

        $entries = collect();

        foreach ($this->files() as $file) {
            if ($since && filemtime($file) < $since->getTimestamp()) {
                continue;
            }

            foreach ($this->parse($this->tail($file)) as $entry) {
                $rank = array_search(strtolower($entry['level']), self::LEVELS, true);

                if ($rank === false || $rank < $minRank) {
                    continue;
                }

                if ($since && $entry['time'] && $entry['time']->lt($since)) {
                    continue;
                }

                if ($search && ! str_contains(strtolower($entry['raw']), strtolower($search))) {
                    continue;
                }

                $entries->push($this->present($entry, basename($file), $traceLines, $vendorFrames));
            }
        }

        return $entries->sortByDesc('timestamp')->values();
    }

    /**
     * Group identical errors (same message, exception and location).
     */
    public function group(Collection $entries): Collection
    {
        return $entries
            ->groupBy(fn (array $e) => md5($e['level'].$e['exception'].$e['file'].$e['line'].$this->normalize($e['message'])))
            ->map(fn (Collection $group) => [
                ...$group->first(),
                'occurrences' => $group->count(),
                'first_seen' => $group->last()['timestamp'],
                'last_seen' => $group->first()['timestamp'],
            ])
            ->sortByDesc('last_seen')
            ->values();
    }

    public function redact(string $text): string
    {
        $secrets = collect([
            config('app.key'),
            config('ai.jev.api_key'),
            config('ai.openrouter.api_key'),
            config('monitoring.mcp_token'),
            config('mail.mailers.smtp.password'),
            config('services.mailgun.secret'),
            config('services.postmark.token'),
            config('services.resend.key'),
            config('services.ses.secret'),
            config('database.connections.'.config('database.default').'.password'),
        ])->filter(fn ($v) => is_string($v) && strlen($v) >= 6);

        foreach ($secrets as $secret) {
            $text = str_replace($secret, '[REDACTED]', $text);
        }

        return preg_replace([
            '/(Bearer\s+)[A-Za-z0-9._\-]{8,}/i',
            '/\bsk-[A-Za-z0-9_\-]{16,}/',
            '/((?:password|passwd|secret|api[_-]?key|token)["\']?\s*[:=]\s*["\']?)[^\s"\',}]{4,}/i',
        ], ['$1[REDACTED]', '[REDACTED]', '$1[REDACTED]'], $text);
    }

    private function tail(string $file): string
    {
        $size = filesize($file);
        $handle = fopen($file, 'rb');

        if ($size > self::MAX_BYTES_PER_FILE) {
            fseek($handle, -self::MAX_BYTES_PER_FILE, SEEK_END);
            fgets($handle); // skip the partial first line
        }

        $content = stream_get_contents($handle);
        fclose($handle);

        return (string) $content;
    }

    private function parse(string $content): array
    {
        preg_match_all(self::ENTRY_PATTERN, $content, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        $entries = [];

        foreach ($matches as $i => $match) {
            $start = $match[0][1];
            $end = $matches[$i + 1][0][1] ?? strlen($content);
            $body = rtrim(substr($content, $start + strlen($match[0][0]), $end - $start - strlen($match[0][0])));

            try {
                $time = \Illuminate\Support\Carbon::parse($match[1][0]);
            } catch (\Throwable) {
                $time = null;
            }

            $entries[] = [
                'time' => $time,
                'environment' => $match[2][0],
                'level' => strtolower($match[3][0]),
                'body' => $body,
                'raw' => $match[0][0].$body,
            ];
        }

        return $entries;
    }

    private function present(array $entry, string $file, int $traceLines, bool $vendorFrames): array
    {
        $body = $this->redact($entry['body']);
        [$firstLine] = explode("\n", $body, 2);

        // Laravel writes exceptions as: message {"exception":"[object] (Class(code: 0): msg at /path:line)\n[stacktrace]\n#0 ..."}
        $exception = $location = null;
        if (preg_match('/\[object\] \(([\w\\\\]+)\(code: [^)]*\): (.*?) at (\S+?):(\d+)\)/s', $body, $m)) {
            $exception = $m[1];
            $location = ['file' => $this->relative($m[3]), 'line' => (int) $m[4]];
        }

        $message = trim(preg_replace('/\s*\{"exception":.*$/s', '', $firstLine));

        $trace = [];
        if (preg_match('/\[stacktrace\]\n(.*?)(?:"\}|\z)/s', $body, $m)) {
            $trace = collect(explode("\n", $m[1]))
                ->map(fn ($l) => $this->relative(trim($l)))
                ->filter(fn ($l) => $l !== '' && ($vendorFrames || ! str_contains($l, 'vendor/')))
                ->take($traceLines)
                ->values()
                ->all();
        }

        return [
            'timestamp' => $entry['time']?->toIso8601String(),
            'level' => $entry['level'],
            'environment' => $entry['environment'],
            'message' => mb_substr($message, 0, 2000),
            'exception' => $exception,
            'file' => $location['file'] ?? null,
            'line' => $location['line'] ?? null,
            'trace' => $trace,
            'context' => $exception ? null : (mb_substr(trim(substr($body, strlen($firstLine))), 0, 2000) ?: null),
            'log_file' => $file,
        ];
    }

    private function relative(string $text): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $text);
    }

    /** Strip volatile parts (ids, numbers, quoted values) so repeats group together */
    private function normalize(string $message): string
    {
        return preg_replace(['/\d+/', '/"[^"]*"|\'[^\']*\'/'], ['#', '?'], $message);
    }
}
