<?php

namespace App\Console\Commands;

use App\Models\MonitoringResult;
use App\Models\Website;
use App\Services\Ai\MonitoringAnalysisService;
use Illuminate\Console\Command;
use Throwable;

class AnalyzeMonitoringResults extends Command
{
    protected $signature = 'monitor:analyze
                            {--id= : Analyze the latest result of this website ID}
                            {--result= : Analyze this monitoring result ID}';

    protected $description = 'Analyze the latest monitoring results with AI (severity, urgency, broken or hacked pages)';

    public function handle(MonitoringAnalysisService $analysis): int
    {
        if (! $analysis->isEnabled()) {
            $this->error('AI analysis is not configured. Set JEV_API_KEY or OPENROUTER_API_KEY.');

            return Command::FAILURE;
        }

        $results = $this->resultsToAnalyze();

        if ($results->isEmpty()) {
            $this->warn('No monitoring results found to analyze.');

            return Command::SUCCESS;
        }

        $failed = 0;

        foreach ($results as $result) {
            try {
                $outcome = $analysis->analyze($result);
                $this->line("{$result->website->url} [{$outcome['severity']}, {$outcome['urgency']}] {$outcome['summary']}");
            } catch (Throwable $e) {
                $failed++;
                $this->error("{$result->website->url}: {$e->getMessage()}");
            }
        }

        return $failed === $results->count() ? Command::FAILURE : Command::SUCCESS;
    }

    private function resultsToAnalyze()
    {
        if ($resultId = $this->option('result')) {
            return MonitoringResult::with('website')->whereKey($resultId)->get();
        }

        $websites = $this->option('id')
            ? Website::whereKey($this->option('id'))->get()
            : Website::where('is_active', true)->get();

        return $websites->map(fn (Website $w) => $w->latestResult()->with('website')->first())->filter()->values();
    }
}
