<?php

namespace App\Filament\Widgets;

use App\Models\Website;
use App\Models\MonitoringResult;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class MonitoringOverviewWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $totalWebsites = Website::count();
        $activeWebsites = Website::where('is_active', true)->count();
        
        $latestResults = MonitoringResult::with('website')
            ->whereHas('website', function ($query) {
                $query->where('is_active', true);
            })
            ->whereIn('id', function ($query) {
                $query->select(\DB::raw('MAX(id)'))
                    ->from('monitoring_results')
                    ->groupBy('website_id');
            })
            ->get();

        $upCount = $latestResults->where('status', 'up')->count();
        $downCount = $latestResults->where('status', 'down')->count();
        $errorCount = $latestResults->where('status', 'error')->count();
        
        $avgResponseTime = $latestResults->where('response_time', '>', 0)->avg('response_time');

        return [
            Stat::make('Total Websites', $totalWebsites)
                ->description($activeWebsites . ' active')
                ->descriptionIcon('heroicon-m-globe-alt')
                ->color('primary'),
            
            Stat::make('Websites Up', $upCount)
                ->description('Currently online')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),
            
            Stat::make('Websites Down', $downCount + $errorCount)
                ->description($downCount . ' down, ' . $errorCount . ' errors')
                ->descriptionIcon('heroicon-m-x-circle')
                ->color($downCount + $errorCount > 0 ? 'danger' : 'success'),
            
            Stat::make('Avg Response Time', $avgResponseTime ? round($avgResponseTime) . ' ms' : 'N/A')
                ->description('Latest check average')
                ->descriptionIcon('heroicon-m-clock')
                ->color($avgResponseTime > 3000 ? 'danger' : ($avgResponseTime > 1000 ? 'warning' : 'success')),
        ];
    }
}