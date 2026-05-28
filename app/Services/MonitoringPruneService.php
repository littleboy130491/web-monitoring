<?php

namespace App\Services;

use App\Models\MonitoringResult;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MonitoringPruneService
{
    /**
     * Build the base prune query.
     */
    public function oldRecordsQuery(Carbon $cutoffDate): Builder
    {
        return MonitoringResult::query()
            ->where('created_at', '<', $cutoffDate);
    }

    /**
     * Return all MonitoringResult records created before the cutoff.
     */
    public function getOldRecords(Carbon $cutoffDate): Collection
    {
        return $this->oldRecordsQuery($cutoffDate)
            ->with('website')
            ->orderBy('id')
            ->get();
    }

    /**
     * Return the number of records eligible for pruning.
     */
    public function countOldRecords(Carbon $cutoffDate): int
    {
        return (clone $this->oldRecordsQuery($cutoffDate))->count();
    }

    /**
     * Return grouped prune stats by website.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function getWebsiteStats(Carbon $cutoffDate)
    {
        return DB::table('monitoring_results')
            ->leftJoin('websites', 'websites.id', '=', 'monitoring_results.website_id')
            ->where('monitoring_results.created_at', '<', $cutoffDate)
            ->selectRaw("
                COALESCE(websites.url, '[Deleted website]') as website_name,
                COUNT(*) as record_count,
                MIN(monitoring_results.created_at) as oldest_record,
                MAX(monitoring_results.created_at) as newest_record
            ")
            ->groupBy('monitoring_results.website_id', 'websites.url')
            ->orderByDesc('record_count')
            ->get();
    }

    /**
     * Return the number of unique screenshot paths eligible for deletion.
     */
    public function countOldScreenshots(Carbon $cutoffDate): int
    {
        return (clone $this->oldRecordsQuery($cutoffDate))
            ->whereNotNull('screenshot_path')
            ->distinct()
            ->count('screenshot_path');
    }

    /**
     * Delete screenshot files for records older than the cutoff.
     * Returns ['deleted' => int, 'errors' => int].
     */
    public function deleteOldScreenshots(Carbon $cutoffDate, ?callable $progress = null): array
    {
        $deleted = 0;
        $errors = 0;

        DB::table('monitoring_results')
            ->where('created_at', '<', $cutoffDate)
            ->whereNotNull('screenshot_path')
            ->select('screenshot_path')
            ->distinct()
            ->orderBy('screenshot_path')
            ->cursor()
            ->each(function ($record) use (&$deleted, &$errors, $progress) {
                try {
                    if (Storage::disk('public')->exists($record->screenshot_path)) {
                        Storage::disk('public')->delete($record->screenshot_path);
                        $deleted++;
                    }
                } catch (\Throwable $e) {
                    $errors++;
                }

                if ($progress) {
                    $progress();
                }
            });

        return ['deleted' => $deleted, 'errors' => $errors];
    }

    /**
     * Delete screenshot files for the given records.
     * Returns ['deleted' => int, 'errors' => int].
     */
    public function deleteScreenshots(Collection $records): array
    {
        $deleted = 0;
        $errors = 0;
        $processedPaths = [];

        foreach ($records->whereNotNull('screenshot_path')->sortBy('screenshot_path') as $record) {
            try {
                if (isset($processedPaths[$record->screenshot_path])) {
                    continue;
                }

                $processedPaths[$record->screenshot_path] = true;

                if (Storage::disk('public')->exists($record->screenshot_path)) {
                    Storage::disk('public')->delete($record->screenshot_path);
                    $deleted++;
                }
            } catch (\Throwable $e) {
                $errors++;
            }
        }

        return ['deleted' => $deleted, 'errors' => $errors];
    }

    /**
     * Collect all scan snapshot .txt files older than the cutoff date.
     */
    public function collectOldScanFiles(Carbon $cutoffDate): array
    {
        $scansDir = storage_path('app/scans');
        if (! is_dir($scansDir)) {
            return [];
        }

        $cutoffTimestamp = $cutoffDate->timestamp;
        $old = [];
        $files = glob($scansDir.'/*/*/*.txt') ?: [];

        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTimestamp) {
                $old[] = $file;
            }
        }

        return $old;
    }

    /**
     * Delete a list of scan snapshot files.
     * Returns ['deleted' => int, 'errors' => int].
     */
    public function deleteScanFiles(array $files): array
    {
        $deleted = 0;
        $errors = 0;

        foreach ($files as $file) {
            try {
                if (file_exists($file)) {
                    unlink($file);
                    $deleted++;
                }
            } catch (\Throwable $e) {
                $errors++;
            }
        }

        $this->removeEmptyScanDirs();

        return ['deleted' => $deleted, 'errors' => $errors];
    }

    /**
     * Delete the given MonitoringResult records from the database.
     * Returns the count of deleted records.
     */
    public function deleteRecords(Collection $records): int
    {
        $count = $records->count();
        MonitoringResult::whereIn('id', $records->pluck('id'))->delete();

        return $count;
    }

    /**
     * Delete old MonitoringResult records in chunks.
     */
    public function deleteOldRecords(Carbon $cutoffDate, ?callable $progress = null): int
    {
        $deleted = 0;

        (clone $this->oldRecordsQuery($cutoffDate))
            ->select('id')
            ->orderBy('id')
            ->chunkById(1000, function ($records) use (&$deleted, $progress) {
                $ids = $records->pluck('id');

                MonitoringResult::whereIn('id', $ids)->delete();

                $count = $ids->count();
                $deleted += $count;

                if ($progress) {
                    $progress($count);
                }
            });

        return $deleted;
    }

    /**
     * Remove empty page/website directories left after scan file deletion.
     */
    public function removeEmptyScanDirs(): void
    {
        $scansDir = storage_path('app/scans');
        if (! is_dir($scansDir)) {
            return;
        }

        foreach (glob($scansDir.'/*/*', GLOB_ONLYDIR) ?: [] as $pageDir) {
            if (count(glob($pageDir.'/*') ?: []) === 0) {
                @rmdir($pageDir);
            }
        }

        foreach (glob($scansDir.'/*', GLOB_ONLYDIR) ?: [] as $siteDir) {
            if (count(glob($siteDir.'/*') ?: []) === 0) {
                @rmdir($siteDir);
            }
        }
    }

    /**
     * Convenience method: prune everything in one call.
     * Returns stats array with keys: records, screenshots, screenshot_errors,
     * scans, scan_errors.
     */
    public function pruneAll(
        Carbon $cutoffDate,
        bool $keepScreenshots = false,
        bool $keepScans = false
    ): array {
        $screenshotStats = ['deleted' => 0, 'errors' => 0];
        if (! $keepScreenshots) {
            $screenshotStats = $this->deleteOldScreenshots($cutoffDate);
        }

        $scanStats = ['deleted' => 0, 'errors' => 0];
        if (! $keepScans) {
            $scanFiles = $this->collectOldScanFiles($cutoffDate);
            $scanStats = $this->deleteScanFiles($scanFiles);
        }

        $deletedRecords = $this->deleteOldRecords($cutoffDate);

        return [
            'records' => $deletedRecords,
            'screenshots' => $screenshotStats['deleted'],
            'screenshot_errors' => $screenshotStats['errors'],
            'scans' => $scanStats['deleted'],
            'scan_errors' => $scanStats['errors'],
        ];
    }
}
