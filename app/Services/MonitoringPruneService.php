<?php

namespace App\Services;

use App\Models\MonitoringResult;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;

class MonitoringPruneService
{
    /**
     * Return all MonitoringResult records created before the cutoff.
     */
    public function getOldRecords(Carbon $cutoffDate): Collection
    {
        return MonitoringResult::where('created_at', '<', $cutoffDate)->get();
    }

    /**
     * Delete screenshot files for the given records.
     * Returns ['deleted' => int, 'errors' => int].
     */
    public function deleteScreenshots(Collection $records): array
    {
        $deleted = 0;
        $errors = 0;

        foreach ($records->whereNotNull('screenshot_path') as $record) {
            try {
                if (Storage::disk('public')->exists($record->screenshot_path)) {
                    Storage::disk('public')->delete($record->screenshot_path);
                    $deleted++;
                }
            } catch (\Exception $e) {
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
        if (!is_dir($scansDir)) {
            return [];
        }

        $cutoffTimestamp = $cutoffDate->timestamp;
        $old = [];

        foreach (glob($scansDir . '/*/*/*.txt') as $file) {
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
            } catch (\Exception $e) {
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
     * Remove empty page/website directories left after scan file deletion.
     */
    public function removeEmptyScanDirs(): void
    {
        $scansDir = storage_path('app/scans');
        if (!is_dir($scansDir)) {
            return;
        }

        foreach (glob($scansDir . '/*/*', GLOB_ONLYDIR) as $pageDir) {
            if (count(glob($pageDir . '/*')) === 0) {
                @rmdir($pageDir);
            }
        }

        foreach (glob($scansDir . '/*', GLOB_ONLYDIR) as $siteDir) {
            if (count(glob($siteDir . '/*')) === 0) {
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
        $records = $this->getOldRecords($cutoffDate);

        $screenshotStats = ['deleted' => 0, 'errors' => 0];
        if (!$keepScreenshots) {
            $screenshotStats = $this->deleteScreenshots($records);
        }

        $scanStats = ['deleted' => 0, 'errors' => 0];
        if (!$keepScans) {
            $scanFiles = $this->collectOldScanFiles($cutoffDate);
            $scanStats = $this->deleteScanFiles($scanFiles);
        }

        $deletedRecords = $this->deleteRecords($records);

        return [
            'records'           => $deletedRecords,
            'screenshots'       => $screenshotStats['deleted'],
            'screenshot_errors' => $screenshotStats['errors'],
            'scans'             => $scanStats['deleted'],
            'scan_errors'       => $scanStats['errors'],
        ];
    }
}
