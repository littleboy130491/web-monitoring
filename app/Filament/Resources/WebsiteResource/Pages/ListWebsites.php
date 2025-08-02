<?php

namespace App\Filament\Resources\WebsiteResource\Pages;

use App\Filament\Imports\WebsiteImporter;
use App\Filament\Resources\WebsiteResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListWebsites extends ListRecords
{
    protected static string $resource = WebsiteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
            Actions\ImportAction::make()
                ->importer(WebsiteImporter::class)
                ->label('Import Websites')
                ->icon('heroicon-o-document-arrow-up')
                ->color('info'),
            Actions\Action::make('monitor_all')
                ->label('Monitor All')
                ->icon('heroicon-o-play')
                ->color('success')
                ->action(function () {
                    $websites = \App\Models\Website::where('is_active', true)->get();

                    foreach ($websites as $website) {
                        \App\Jobs\MonitorWebsiteJob::dispatch($website, false, 30);
                    }

                    $count = $websites->count();

                    \Filament\Notifications\Notification::make()
                        ->title('Monitoring Started')
                        ->body("Queued {$count} websites for monitoring")
                        ->success()
                        ->send();
                })
                ->requiresConfirmation()
                ->modalHeading('Monitor All Active Websites')
                ->modalDescription('This will queue all active websites for monitoring. Continue?')
                ->modalSubmitActionLabel('Yes, Monitor All'),
            Actions\Action::make('monitor_all_with_screenshots')
                ->label('Monitor All (+ Screenshots)')
                ->icon('heroicon-o-camera')
                ->color('warning')
                ->action(function () {
                    $websites = \App\Models\Website::where('is_active', true)->get();

                    foreach ($websites as $website) {
                        \App\Jobs\MonitorWebsiteJob::dispatch($website, true, 30);
                    }

                    $count = $websites->count();

                    \Filament\Notifications\Notification::make()
                        ->title('Monitoring with Screenshots Started')
                        ->body("Queued {$count} websites for monitoring with screenshots")
                        ->success()
                        ->send();
                })
                ->requiresConfirmation()
                ->modalHeading('Monitor All with Screenshots')
                ->modalDescription('This will queue all active websites for monitoring including screenshots. This may take longer. Continue?')
                ->modalSubmitActionLabel('Yes, Monitor All with Screenshots'),
        ];
    }

}
