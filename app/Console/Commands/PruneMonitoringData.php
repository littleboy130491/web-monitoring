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
                            {--force : Run without interactive confirmation}
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
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $keepScreenshots = (bool) $this->option('keep-screenshots');
        $keepScans = (bool) $this->option('keep-scans');

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

        $oldRecordCount = $this->pruneService->countOldRecords($cutoffDate);

        if ($oldRecordCount === 0) {
            $this->info('✅ No old monitoring data found to prune.');
            return Command::SUCCESS;
        }

        $this->info("📊 Found {$oldRecordCount} monitoring records to prune:");

        foreach ($this->pruneService->getWebsiteStats($cutoffDate) as $stats) {
            $this->line(
                "  📍 {$stats->website_name}: {$stats->record_count} records ({$stats->oldest_record} to {$stats->newest_record})"
            );
        }

        $screenshotCount = !$keepScreenshots
            ? $this->pruneService->countOldScreenshots($cutoffDate)
            : 0;

        $scanFilesToDelete = !$keepScans
            ? $this->pruneService->collectOldScanFiles($cutoffDate)
            : [];

        if ($screenshotCount > 0) {
            $this->info("📸 Found {$screenshotCount} screenshot(s) to delete");
        }
        if (!empty($scanFilesToDelete)) {
            $this->info("📄 Found " . count($scanFilesToDelete) . " scan snapshot(s) to delete");
        }

        if ($dryRun) {
            $this->info('📋 Dry run summary:');
            $this->line("  🔍 Would delete: {$oldRecordCount} monitoring records");
            if ($screenshotCount > 0) {
                $this->line("  🔍 Would delete: {$screenshotCount} screenshot(s)");
            }
            if (!empty($scanFilesToDelete)) {
                $this->line("  🔍 Would delete: " . count($scanFilesToDelete) . " scan snapshot(s)");
            }
            return Command::SUCCESS;
        }

        // Build confirmation message
        $extras = [];
        if ($screenshotCount > 0)                  $extras[] = $screenshotCount . " screenshot(s)";
        if (!empty($scanFilesToDelete))          $extras[] = count($scanFilesToDelete) . " scan snapshot(s)";
        $extraMsg = $extras ? " and " . implode(', ', $extras) : "";

        if (!$force && !$this->confirm("Delete {$oldRecordCount} monitoring records{$extraMsg}?")) {
            $this->info('❌ Operation cancelled.');
            return Command::SUCCESS;
        }

        // --- Screenshots ---
        $deletedScreenshots = 0;
        $screenshotErrors = 0;
        if ($screenshotCount > 0) {
            $this->info('🗑️  Deleting screenshots...');
            $bar = $this->output->createProgressBar($screenshotCount);
            $bar->start();
            $stats = $this->pruneService->deleteOldScreenshots($cutoffDate, function () use ($bar) {
                $bar->advance();
            });
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
        $bar = $this->output->createProgressBar($oldRecordCount);
        $bar->start();
        $deletedRecords = $this->pruneService->deleteOldRecords($cutoffDate, function (int $count) use ($bar) {
            $bar->advance($count);
        });
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
