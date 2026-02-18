<?php

namespace App\Console\Commands;

use App\Services\MonitoringPruneService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class PruneMonitoringData extends Command
{
    protected $signature = 'monitor:prune
                            {--days=30 : Number of days to retain data (default: 30)}
                            {--dry-run : Show what would be deleted without actually deleting}
                            {--keep-screenshots : Keep screenshot files even if data is deleted}
                            {--keep-scans : Keep scan snapshot files even if data is deleted}';

    protected $description = 'Prune old monitoring data, screenshots, and scan snapshots older than specified days';

    public function __construct(private MonitoringPruneService $pruneService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $days = (float) $this->option('days');
        $dryRun = $this->option('dry-run');
        $keepScreenshots = $this->option('keep-screenshots');
        $keepScans = $this->option('keep-scans');

        if ($days < 0) {
            $this->error('Days must be a non-negative number.');
            return Command::FAILURE;
        }

        $cutoffDate = Carbon::now()->subDays($days);

        if ($days == 0) {
            $this->warn("⚠️  WARNING: --days=0 will delete ALL monitoring data!");
            $this->info("🧹 Pruning ALL monitoring data (before {$cutoffDate->format('Y-m-d H:i:s')})");
        } else {
            $this->info("🧹 Pruning monitoring data older than {$days} days (before {$cutoffDate->format('Y-m-d H:i:s')})");
        }

        if ($dryRun) {
            $this->warn('🔍 DRY RUN MODE - No data will actually be deleted');
        }

        $oldRecords = $this->pruneService->getOldRecords($cutoffDate);

        if ($oldRecords->isEmpty()) {
            $this->info('✅ No old monitoring data found to prune.');
            return Command::SUCCESS;
        }

        $this->info("📊 Found {$oldRecords->count()} monitoring records to prune:");

        $websiteStats = $oldRecords->groupBy('website.name')->map(fn($r) => [
            'count'  => $r->count(),
            'oldest' => $r->min('created_at'),
            'newest' => $r->max('created_at'),
        ]);

        foreach ($websiteStats as $name => $stats) {
            $this->line("  📍 {$name}: {$stats['count']} records ({$stats['oldest']} to {$stats['newest']})");
        }

        $screenshotsToDelete = !$keepScreenshots
            ? $oldRecords->whereNotNull('screenshot_path')->pluck('screenshot_path')->unique()->values()
            : collect();

        $scanFilesToDelete = !$keepScans
            ? $this->pruneService->collectOldScanFiles($cutoffDate)
            : [];

        if ($screenshotsToDelete->isNotEmpty()) {
            $this->info("📸 Found {$screenshotsToDelete->count()} screenshot(s) to delete");
        }
        if (!empty($scanFilesToDelete)) {
            $this->info("📄 Found " . count($scanFilesToDelete) . " scan snapshot(s) to delete");
        }

        if ($dryRun) {
            $this->info('📋 Dry run summary:');
            $this->line("  🔍 Would delete: {$oldRecords->count()} monitoring records");
            if ($screenshotsToDelete->isNotEmpty()) {
                $this->line("  🔍 Would delete: {$screenshotsToDelete->count()} screenshot(s)");
            }
            if (!empty($scanFilesToDelete)) {
                $this->line("  🔍 Would delete: " . count($scanFilesToDelete) . " scan snapshot(s)");
            }
            return Command::SUCCESS;
        }

        // Build confirmation message
        $extras = [];
        if ($screenshotsToDelete->isNotEmpty()) $extras[] = $screenshotsToDelete->count() . " screenshot(s)";
        if (!empty($scanFilesToDelete))          $extras[] = count($scanFilesToDelete) . " scan snapshot(s)";
        $extraMsg = $extras ? " and " . implode(', ', $extras) : "";

        if (!$this->confirm("Delete {$oldRecords->count()} monitoring records{$extraMsg}?")) {
            $this->info('❌ Operation cancelled.');
            return Command::SUCCESS;
        }

        // --- Screenshots ---
        $deletedScreenshots = 0;
        $screenshotErrors = 0;
        if ($screenshotsToDelete->isNotEmpty()) {
            $this->info('🗑️  Deleting screenshots...');
            $bar = $this->output->createProgressBar($screenshotsToDelete->count());
            $bar->start();
            $stats = $this->pruneService->deleteScreenshots($oldRecords);
            $deletedScreenshots = $stats['deleted'];
            $screenshotErrors = $stats['errors'];
            $bar->finish();
            $this->line('');
        }

        // --- Scan files ---
        $deletedScans = 0;
        $scanErrors = 0;
        if (!empty($scanFilesToDelete)) {
            $this->info('🗑️  Deleting scan snapshots...');
            $bar = $this->output->createProgressBar(count($scanFilesToDelete));
            $bar->start();
            $stats = $this->pruneService->deleteScanFiles($scanFilesToDelete);
            $deletedScans = $stats['deleted'];
            $scanErrors = $stats['errors'];
            $bar->finish();
            $this->line('');
        }

        // --- DB records ---
        $this->info('🗑️  Deleting monitoring records...');
        $bar = $this->output->createProgressBar($oldRecords->count());
        $bar->start();
        $deletedRecords = $this->pruneService->deleteRecords($oldRecords);
        $bar->finish();
        $this->line('');

        // --- Summary ---
        $this->info('📋 Pruning Summary:');
        $this->line("  ✅ Deleted: {$deletedRecords} monitoring records");

        if (!$keepScreenshots) {
            $this->line("  ✅ Deleted: {$deletedScreenshots} screenshot(s)");
            if ($screenshotErrors > 0) $this->line("  ⚠️  Screenshot errors: {$screenshotErrors}");
        }
        if (!$keepScans) {
            $this->line("  ✅ Deleted: {$deletedScans} scan snapshot(s)");
            if ($scanErrors > 0) $this->line("  ⚠️  Scan file errors: {$scanErrors}");
        }

        if ($deletedRecords > 0) {
            $spaceSaved = ($deletedRecords * 1024) / 1024 / 1024;
            $this->line("  💾 Estimated space saved: " . number_format($spaceSaved, 2) . " MB");
        }

        $this->info('🎉 Pruning completed successfully!');
        return Command::SUCCESS;
    }
}
