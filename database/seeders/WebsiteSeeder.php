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
            
            // Convert string boolean to actual boolean
            $website['is_active'] = (bool) $website['is_active'];
            $website['check_interval'] = (int) $website['check_interval'];
            
            Website::create($website);
        }

        $this->command->info("Imported " . (count($lines)) . " websites from CSV");
    }
}
