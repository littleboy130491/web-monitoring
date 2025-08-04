<?php

namespace App\Filament\Resources\MonitoringResultResource\Pages;

use App\Filament\Resources\MonitoringResultResource;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;

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
                    $days = $data['days'];
                    $cutoffDate = now()->subDays($days);
                    
                    // Get records to delete (with screenshots)
                    $recordsToDelete = \App\Models\MonitoringResult::where('created_at', '<', $cutoffDate)->get();
                    $deletedCount = $recordsToDelete->count();
                    
                    // Delete screenshots first
                    $deletedScreenshots = 0;
                    foreach ($recordsToDelete->whereNotNull('screenshot_path') as $record) {
                        if (Storage::disk('public')->exists($record->screenshot_path)) {
                            Storage::disk('public')->delete($record->screenshot_path);
                            $deletedScreenshots++;
                        }
                    }
                    
                    // Delete records
                    \App\Models\MonitoringResult::where('created_at', '<', $cutoffDate)->delete();
                    
                    $message = "Deleted {$deletedCount} monitoring results";
                    if ($deletedScreenshots > 0) {
                        $message .= " and {$deletedScreenshots} screenshots";
                    }
                    if ($days == 0) {
                        $message .= " (all data)";
                    } else {
                        $message .= " older than {$days} days";
                    }
                    
                    \Filament\Notifications\Notification::make()
                        ->title('Data Pruned Successfully')
                        ->body($message)
                        ->success()
                        ->send();
                })
                ->requiresConfirmation()
                ->modalDescription('This action will permanently delete old monitoring results and cannot be undone.'),
        ];
    }
}
