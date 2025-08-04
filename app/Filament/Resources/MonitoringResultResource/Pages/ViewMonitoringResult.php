<?php

namespace App\Filament\Resources\MonitoringResultResource\Pages;

use App\Filament\Resources\MonitoringResultResource;
use Filament\Actions;
use Filament\Infolists\Components;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewMonitoringResult extends ViewRecord
{
    protected static string $resource = MonitoringResultResource::class;

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
                Components\Section::make('Website Information')
                    ->schema([
                        Components\TextEntry::make('website.name')
                            ->label('Website'),
                        Components\TextEntry::make('website.url')
                            ->label('URL')
                            ->url(fn($record) => $record->website->url)
                            ->openUrlInNewTab(),
                        Components\TextEntry::make('checked_at')
                            ->label('Checked At')
                            ->dateTime(),
                    ])
                    ->columns(3),

                Components\Section::make('Status & Performance')
                    ->schema([
                        Components\TextEntry::make('status')
                            ->badge()
                            ->color(fn(string $state): string => match ($state) {
                                'up' => 'success',
                                'down' => 'danger',
                                'error' => 'danger',
                                'warning' => 'warning',
                                default => 'gray',
                            }),
                        Components\TextEntry::make('status_code')
                            ->label('HTTP Status Code')
                            ->badge()
                            ->color(fn(?int $state): string => match (true) {
                                $state >= 200 && $state < 300 => 'success',
                                $state >= 300 && $state < 400 => 'warning',
                                $state >= 400 => 'danger',
                                default => 'gray',
                            }),
                        Components\TextEntry::make('response_time')
                            ->label('Response Time')
                            ->suffix(' ms')
                            ->color(fn(?int $state): string => match (true) {
                                $state < 1000 => 'success',
                                $state < 3000 => 'warning',
                                $state >= 3000 => 'danger',
                                default => 'gray',
                            }),
                    ])
                    ->columns(3),

                Components\Section::make('Content & Changes')
                    ->schema([
                        Components\IconEntry::make('content_changed')
                            ->label('Content Changed')
                            ->boolean(),
                        Components\TextEntry::make('content_hash')
                            ->label('Content Hash')
                            ->limit(50)
                            ->tooltip(fn($state) => $state),
                    ])
                    ->columns(2)
                    ->collapsible(),

                Components\Section::make('Screenshot')
                    ->schema([
                        Components\ImageEntry::make('screenshot_path')
                            ->label('Screenshot')
                            ->disk('public')
                            ->height(400)
                            ->width('100%'),
                    ])
                    ->visible(fn($record) => !empty($record->screenshot_path))
                    ->collapsible(),

                Components\Section::make('SSL Information')
                    ->schema([
                        Components\TextEntry::make('ssl_info')
                            ->label('SSL Certificate Details')
                            ->formatStateUsing(function ($state) {
                                if (empty($state)) {
                                    return 'N/A';
                                }

                                // Handle JSON string
                                if (is_string($state)) {
                                    $decoded = json_decode($state, true);
                                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                                        $state = $decoded;
                                    } else {
                                        return e($state);
                                    }
                                }

                                if (is_array($state)) {
                                    $formatted = [];
                                    foreach ($state as $key => $value) {
                                        $displayValue = is_array($value) ? json_encode($value) : (string) $value;
                                        $formatted[] = '<div><strong>' . e($key) . ':</strong> ' . e($displayValue) . '</div>';
                                    }
                                    return '<div class="space-y-1">' . implode('', $formatted) . '</div>';
                                }

                                return e((string) $state);
                            })
                            ->html(),
                    ])
                    ->visible(fn($record) => !empty($record->ssl_info))
                    ->collapsible(),

                Components\Section::make('HTTP Headers')
                    ->schema([
                        Components\TextEntry::make('headers')
                            ->label('Response Headers')
                            ->formatStateUsing(fn($state) => $state ? '<pre class="text-xs bg-gray-50 p-2 rounded">' . e(is_string($state) ? $state : json_encode($state, JSON_PRETTY_PRINT)) . '</pre>' : 'N/A')
                            ->html()
                            ->copyable()
                            ->copyableState(fn($state) => $state),
                    ])
                    ->extraAttributes(['class' => 'overflow-x-auto'])
                    ->visible(fn($record) => !empty($record->headers))
                    ->collapsible(),

                Components\Section::make('Error Details')
                    ->schema([
                        Components\TextEntry::make('error_message')
                            ->label('Error Message')
                            ->color('danger'),
                    ])
                    ->visible(fn($record) => !empty($record->error_message))
                    ->collapsible(),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('recheck')
                ->label('Recheck')
                ->icon('heroicon-o-arrow-path')
                ->color('primary')
                ->action(function ($record) {
                    \App\Jobs\MonitorWebsiteJob::dispatch($record->website, false, 30);
                    
                    \Filament\Notifications\Notification::make()
                        ->title('Monitoring Queued')
                        ->body("Website '{$record->website->url}' has been queued for monitoring")
                        ->success()
                        ->send();
                })
                ->requiresConfirmation()
                ->modalHeading('Recheck Website')
                ->modalDescription(fn ($record) => "Queue monitoring for '{$record->website->name}'?")
                ->modalSubmitActionLabel('Yes, Recheck'),
            Actions\Action::make('recheck_with_screenshot')
                ->label('Recheck + Screenshot')
                ->icon('heroicon-o-camera')
                ->color('warning')
                ->action(function ($record) {
                    \App\Jobs\MonitorWebsiteJob::dispatch($record->website, true, 30);
                    
                    \Filament\Notifications\Notification::make()
                        ->title('Monitoring with Screenshot Queued')
                        ->body("Website '{$record->website->url}' has been queued for monitoring with screenshot")
                        ->success()
                        ->send();
                })
                ->requiresConfirmation()
                ->modalHeading('Recheck Website with Screenshot')
                ->modalDescription(fn ($record) => "Queue monitoring with screenshot for '{$record->website->name}'? This may take longer.")
                ->modalSubmitActionLabel('Yes, Recheck with Screenshot'),
        ];
    }
}