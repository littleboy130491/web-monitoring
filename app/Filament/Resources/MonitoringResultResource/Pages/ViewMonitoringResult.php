<?php

namespace App\Filament\Resources\MonitoringResultResource\Pages;

use App\Filament\Actions\MonitoringActions;
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

                Components\Section::make('Deep Scan Results')
                    ->schema([
                        Components\TextEntry::make('scan_summary')
                            ->label('Page Snapshots')
                            ->getStateUsing(function ($record): string {
                                $data = $record->scan_results;
                                if (empty($data)) return 'No scan data available.';

                                $rows = '';
                                foreach ($data['pages'] ?? [] as $page) {
                                    $badge = $page['significant']
                                        ? '<span style="color:#ef4444;font-weight:bold"> ⚠ SIGNIFICANT</span>'
                                        : '<span style="color:#22c55e"> ✓ OK</span>';
                                    $prev = $page['previous_file_found'] ? '' : ' <em>(first scan)</em>';
                                    $rows .= "<div class='mb-1'>"
                                        . "<strong>" . e($page['slug']) . "</strong> — "
                                        . e($page['change_percent']) . "% changed"
                                        . $badge . $prev
                                        . "</div>";
                                }

                                $broken = $data['broken_assets'] ?? [];
                                if ($broken) {
                                    $rows .= "<div class='mt-3'><strong style='color:#f59e0b'>Broken Assets (" . count($broken) . "):</strong></div>";
                                    foreach ($broken as $asset) {
                                        $rows .= "<div class='ml-2 text-sm' style='color:#ef4444'>"
                                            . e($asset['type']) . " 404: " . e($asset['url'])
                                            . "</div>";
                                    }
                                }

                                return "<div class='space-y-1 text-sm font-mono'>{$rows}</div>";
                            })
                            ->html()
                            ->columnSpanFull(),
                        Components\IconEntry::make('scan_results.any_significant_change')
                            ->label('Significant Content Changed')
                            ->boolean()
                            ->getStateUsing(fn($record) => (bool) ($record->scan_results['any_significant_change'] ?? false)),
                    ])
                    ->visible(fn ($record) => !empty($record->scan_results))
                    ->collapsible(),

                Components\Section::make('Domain Expiry')
                    ->schema([
                        Components\TextEntry::make('domain_expires_at')
                            ->label('Expiry Date')
                            ->date('Y-m-d')
                            ->placeholder('Not available'),
                        Components\TextEntry::make('domain_days_until_expiry')
                            ->label('Days Until Expiry')
                            ->formatStateUsing(fn (?int $state): string => match (true) {
                                $state === null => 'N/A',
                                $state <= 0     => 'EXPIRED',
                                default         => "{$state} days",
                            })
                            ->color(fn (?int $state): string => match (true) {
                                $state === null => 'gray',
                                $state <= 0     => 'danger',
                                $state <= 7     => 'danger',
                                $state <= 30    => 'warning',
                                default         => 'success',
                            })
                            ->badge(),
                    ])
                    ->columns(2)
                    ->visible(fn ($record) => $record->domain_expires_at !== null)
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
                ->action(fn($record) => MonitoringActions::dispatchMonitor($record->website))
                ->requiresConfirmation()
                ->modalHeading('Recheck Website')
                ->modalDescription(fn($record) => "Queue monitoring for '{$record->website->url}'?")
                ->modalSubmitActionLabel('Yes, Recheck'),
            Actions\Action::make('recheck_with_screenshot')
                ->label('Recheck + Screenshot')
                ->icon('heroicon-o-camera')
                ->color('warning')
                ->action(fn($record) => MonitoringActions::dispatchMonitor($record->website, true))
                ->requiresConfirmation()
                ->modalHeading('Recheck Website with Screenshot')
                ->modalDescription(fn($record) => "Queue monitoring with screenshot for '{$record->website->url}'? This may take longer.")
                ->modalSubmitActionLabel('Yes, Recheck with Screenshot'),
        ];
    }
}