<?php

namespace App\Filament\Resources\MonitoringResultResource\Pages;

use App\Filament\Resources\MonitoringResultResource;
use App\Services\MonitoringPruneService;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ListRecords;

class ListMonitoringResults extends ListRecords
{
    protected static string $resource = MonitoringResultResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('prune_data')
                ->label('Prune Old Data')
                ->icon('heroicon-o-trash')
                ->color('warning')
                ->form([
                    Forms\Components\TextInput::make('days')
                        ->label('Days to keep')
                        ->numeric()
                        ->default(30)
                        ->required()
                        ->minValue(0)
                        ->maxValue(365)
                        ->helperText('Data older than this many days will be deleted. Use 0 to delete all data.'),
                ])
                ->action(function (array $data) {
                    $days = (int) $data['days'];
                    $cutoffDate = now()->subDays($days);

                    $stats = app(MonitoringPruneService::class)->pruneAll($cutoffDate);

                    $parts = ["{$stats['records']} monitoring result(s)"];
                    if ($stats['screenshots'] > 0) $parts[] = "{$stats['screenshots']} screenshot(s)";
                    if ($stats['scans'] > 0)       $parts[] = "{$stats['scans']} scan snapshot(s)";

                    $suffix = $days === 0 ? " (all data)" : " older than {$days} day(s)";

                    \Filament\Notifications\Notification::make()
                        ->title('Data Pruned Successfully')
                        ->body('Deleted ' . implode(', ', $parts) . $suffix)
                        ->success()
                        ->send();
                })
                ->requiresConfirmation()
                ->modalDescription('This action will permanently delete old monitoring results, screenshots, and scan snapshots. This cannot be undone.'),
        ];
    }
}
