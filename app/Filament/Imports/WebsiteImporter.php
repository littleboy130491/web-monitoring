<?php

namespace App\Filament\Imports;

use App\Models\Website;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;

class WebsiteImporter extends Importer
{
    protected static ?string $model = Website::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('url')
                ->requiredMapping()
                ->rules(['required', 'max:255', 'url']),
            ImportColumn::make('description')
                ->rules(['nullable']),
            ImportColumn::make('is_active')
                ->boolean()
                ->castStateUsing(function (?bool $state): bool {
                    return $state ?? true;
                }),
            ImportColumn::make('check_interval')
                ->numeric()
                ->rules(['nullable', 'integer', 'min:60'])
                ->castStateUsing(function (?int $state): int {
                    return $state ?? 3600;
                }),
            ImportColumn::make('headers')
                ->rules(['nullable']),
        ];
    }

    public function resolveRecord(): ?Website
    {
        return Website::firstOrNew([
            'url' => $this->data['url'],
        ]);
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your website import has completed and ' . number_format($import->successful_rows) . ' ' . str('row')->plural($import->successful_rows) . ' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' ' . number_format($failedRowsCount) . ' ' . str('row')->plural($failedRowsCount) . ' failed to import.';
        }

        return $body;
    }
}
