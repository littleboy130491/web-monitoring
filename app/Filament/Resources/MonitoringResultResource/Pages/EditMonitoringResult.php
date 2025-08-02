<?php

namespace App\Filament\Resources\MonitoringResultResource\Pages;

use App\Filament\Resources\MonitoringResultResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMonitoringResult extends EditRecord
{
    protected static string $resource = MonitoringResultResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
