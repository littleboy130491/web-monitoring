<?php

namespace App\Console\Commands;

use App\Models\MonitoringResult;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class PruneMonitoringData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'monitor:prune
                            {--days=30 : Number of days to retain data (default: 30)}
                            {--dry-run : Show what would be deleted without actually deleting}
                            {--keep-screenshots : Keep screenshot files even if data is deleted}
                            {--keep-scans : Keep scan snapshot files even if data is deleted}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune old monitoring data, screenshots, and scan snapshots older than specified days';

    /**
     * Execute the console command.
     */
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

        // Find old monitoring results
        $oldResults = MonitoringResult::where('created_at', '<', $cutoffDate)->get();

        if ($oldResults->isEmpty()) {
            $this->info('✅ No old monitoring data found to prune.');
            return Command::SUCCESS;
        }

        $this->info("📊 Found {$oldResults->count()} monitoring records to prune:");

        // Group by website for better reporting
        $websiteStats = $oldResults->groupBy('website.name')->map(function ($results, $websiteName) {
            return [
                'count' => $results->count(),
                'oldest' => $results->min('created_at'),
                'newest' => $results->max('created_at'),
            ];
        });

        foreach ($websiteStats as $websiteName => $stats) {
            $this->line("  📍 {$websiteName}: {$stats['count']} records ({$stats['oldest']} to {$stats['newest']})");
        }

        // Handle screenshots
        $screenshotsToDelete = [];
        if (!$keepScreenshots) {
            $screenshotsToDelete = $oldResults->whereNotNull('screenshot_path')
                ->pluck('screenshot_path')
                ->unique()
                ->toArray();

            if (!empty($screenshotsToDelete)) {
                $this->info("📸 Found " . count($screenshotsToDelete) . " screenshots to delete");
            }
        }

        // Handle scan snapshot files
        $scanFilesToDelete = [];
        if (!$keepScans) {
            $scanFilesToDelete = $this->collectOldScanFiles($cutoffDate);
            if (!empty($scanFilesToDelete)) {
                $this->info("📄 Found " . count($scanFilesToDelete) . " scan snapshot(s) to delete");
            }
        }

        // Confirm deletion
        if (!$dryRun) {
            $extras = [];
            if (!$keepScreenshots && !empty($screenshotsToDelete)) {
                $extras[] = count($screenshotsToDelete) . " screenshots";
            }
            if (!$keepScans && !empty($scanFilesToDelete)) {
                $extras[] = count($scanFilesToDelete) . " scan snapshot(s)";
            }
            $extraMsg = $extras ? " and " . implode(', ', $extras) : "";

            if (!$this->confirm("Are you sure you want to delete {$oldResults->count()} monitoring records{$extraMsg}?")) {
                $this->info('❌ Operation cancelled.');
                return Command::SUCCESS;
            }
        }

        $deletedRecords = 0;
        $deletedScreenshots = 0;
        $screenshotErrors = 0;
        $deletedScans = 0;
        $scanErrors = 0;

        if (!$dryRun) {
            // Delete screenshots first
            if (!$keepScreenshots && !empty($screenshotsToDelete)) {
                $this->info('🗑️  Deleting screenshots...');

                $screenshotBar = $this->output->createProgressBar(count($screenshotsToDelete));
                $screenshotBar->start();

                foreach ($screenshotsToDelete as $screenshotPath) {
                    try {
                        if (Storage::disk('public')->exists($screenshotPath)) {
                            Storage::disk('public')->delete($screenshotPath);
                            $deletedScreenshots++;
                        }
                    } catch (\Exception $e) {
                        $screenshotErrors++;
                        $this->warn("Failed to delete screenshot: {$screenshotPath} - {$e->getMessage()}");
                    }
                    $screenshotBar->advance();
                }
                $screenshotBar->finish();
                $this->line('');
            }

            // Delete scan snapshots
            if (!$keepScans && !empty($scanFilesToDelete)) {
                $this->info('🗑️  Deleting scan snapshots...');

                $scanBar = $this->output->createProgressBar(count($scanFilesToDelete));
                $scanBar->start();

                foreach ($scanFilesToDelete as $filePath) {
                    try {
                        if (file_exists($filePath)) {
                            unlink($filePath);
                            $deletedScans++;
                        }
                    } catch (\Exception $e) {
                        $scanErrors++;
                        $this->warn("Failed to delete scan file: {$filePath} - {$e->getMessage()}");
                    }
                    $scanBar->advance();
                }
                $scanBar->finish();
                $this->line('');

                // Remove empty directories left behind
                $this->removeEmptyScanDirs();
            }

            // Delete monitoring records
            $this->info('🗑️  Deleting monitoring records...');

            $recordBar = $this->output->createProgressBar($oldResults->count());
            $recordBar->start();

            // Delete in chunks to avoid memory issues
            $oldResults->chunk(100)->each(function ($chunk) use (&$deletedRecords, $recordBar) {
                $chunk->each(function ($result) use (&$deletedRecords, $recordBar) {
                    $result->delete();
                    $deletedRecords++;
                    $recordBar->advance();
                });
            });

            $recordBar->finish();
            $this->line('');
        }

        // Summary
        $this->info('📋 Pruning Summary:');

        if ($dryRun) {
            $this->line("  🔍 Would delete: {$oldResults->count()} monitoring records");
            if (!$keepScreenshots && !empty($screenshotsToDelete)) {
                $this->line("  🔍 Would delete: " . count($screenshotsToDelete) . " screenshots");
            }
            if (!$keepScans && !empty($scanFilesToDelete)) {
                $this->line("  🔍 Would delete: " . count($scanFilesToDelete) . " scan snapshot(s)");
            }
        } else {
            $this->line("  ✅ Deleted: {$deletedRecords} monitoring records");
            if (!$keepScreenshots) {
                $this->line("  ✅ Deleted: {$deletedScreenshots} screenshots");
                if ($screenshotErrors > 0) {
                    $this->line("  ⚠️  Screenshot errors: {$screenshotErrors}");
                }
            }
            if (!$keepScans) {
                $this->line("  ✅ Deleted: {$deletedScans} scan snapshot(s)");
                if ($scanErrors > 0) {
                    $this->line("  ⚠️  Scan file errors: {$scanErrors}");
                }
            }
        }

        // Calculate space saved (approximate)
        if (!$dryRun && $deletedRecords > 0) {
            $avgRecordSize = 1024; // Approximate size per record in bytes
            $spaceSaved = ($deletedRecords * $avgRecordSize) / 1024 / 1024; // Convert to MB
            $this->line("  💾 Estimated space saved: " . number_format($spaceSaved, 2) . " MB");
        }
        
        $this->info('🎉 Pruning completed successfully!');
    }

    /**
     * Collect all scan snapshot .txt files older than the cutoff date.
     */
    private function collectOldScanFiles(\Carbon\Carbon $cutoffDate): array
    {
        $scansDir = storage_path('app/scans');
        if (!is_dir($scansDir)) {
            return [];
        }

        $old = [];
        $cutoffTimestamp = $cutoffDate->timestamp;

        // Glob pattern: scans/{website}/{page}/*.txt
        foreach (glob($scansDir . '/*/*/*.txt') as $file) {
            if (filemtime($file) < $cutoffTimestamp) {
                $old[] = $file;
            }
        }

        return $old;
    }

    /**
     * Remove empty page/website directories left after scan file deletion.
     */
    private function removeEmptyScanDirs(): void
    {
        $scansDir = storage_path('app/scans');
        if (!is_dir($scansDir)) {
            return;
        }

        // Page-level directories
        foreach (glob($scansDir . '/*/*', GLOB_ONLYDIR) as $pageDir) {
            if (count(glob($pageDir . '/*')) === 0) {
                @rmdir($pageDir);
            }
        }

        // Website-level directories
        foreach (glob($scansDir . '/*', GLOB_ONLYDIR) as $siteDir) {
            if (count(glob($siteDir . '/*')) === 0) {
                @rmdir($siteDir);
            }
        }
    }
}
