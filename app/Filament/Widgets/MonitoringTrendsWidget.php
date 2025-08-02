<?php

namespace App\Filament\Widgets;

use App\Models\MonitoringResult;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;

class MonitoringTrendsWidget extends ChartWidget
{
    protected static ?string $heading = 'Response Time Trends (Last 24 Hours)';
    
    protected static ?int $sort = 2;

    protected function getData(): array
    {
        $data = MonitoringResult::where('checked_at', '>=', now()->subDay())
            ->where('response_time', '>', 0)
            ->orderBy('checked_at')
            ->get()
            ->groupBy(function ($result) {
                return $result->checked_at->format('H:00');
            })
            ->map(function ($group) {
                return round($group->avg('response_time'));
            });

        return [
            'datasets' => [
                [
                    'label' => 'Avg Response Time (ms)',
                    'data' => $data->values()->toArray(),
                    'borderColor' => '#10B981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                ],
            ],
            'labels' => $data->keys()->toArray(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'title' => [
                        'display' => true,
                        'text' => 'Response Time (ms)',
                    ],
                ],
                'x' => [
                    'title' => [
                        'display' => true,
                        'text' => 'Hour',
                    ],
                ],
            ],
        ];
    }
}