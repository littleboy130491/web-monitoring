<?php

namespace Database\Seeders;

use App\Models\Website;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class WebsiteSeeder extends Seeder
{
    public function run(): void
    {
        $csvPath = storage_path('app/websites.csv');
        
        if (!file_exists($csvPath)) {
            $this->command->error("CSV file not found: {$csvPath}");
            return;
        }

        $csvContent = file_get_contents($csvPath);
        $lines = explode("\n", trim($csvContent));
        
        // Skip header row
        $header = str_getcsv(array_shift($lines));
        
        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }
            
            $data = str_getcsv($line);
            
            if (count($data) !== count($header)) {
                $this->command->warn("Skipping invalid CSV line: {$line}");
                continue;
            }
            
            $website = array_combine($header, $data);
            
            // Handle required URL field
            if (empty($website['url'])) {
                $this->command->warn("Skipping line with missing URL: {$line}");
                continue;
            }
            
            // Set default name from URL if empty
            if (empty($website['name'])) {
                $website['name'] = parse_url($website['url'], PHP_URL_HOST) ?: $website['url'];
            }
            
            // Set default description (empty is fine)
            if (empty($website['description'])) {
                $website['description'] = null;
            }
            
            // Set default is_active to true if empty or not set
            if ($website['is_active'] === '' || $website['is_active'] === null) {
                $website['is_active'] = true;
            } else {
                $website['is_active'] = (bool) ((int) $website['is_active']);
            }
            
            // Set default check_interval to 3600 seconds if empty
            if ($website['check_interval'] === '' || $website['check_interval'] === null) {
                $website['check_interval'] = 3600;
            } else {
                $website['check_interval'] = (int) $website['check_interval'];
            }
            
            Website::create($website);
        }

        $this->command->info("Imported " . (count($lines)) . " websites from CSV");
    }
}
