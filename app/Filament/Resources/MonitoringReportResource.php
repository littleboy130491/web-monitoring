<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MonitoringReportResource\Pages;
use App\Models\MonitoringReport;
use App\Services\MonitoringReportService;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Form;
use Illuminate\Database\Eloquent\Builder;

class MonitoringReportResource extends Resource
{
    protected static ?string $model = MonitoringReport::class;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'Monitoring';

    protected static ?string $navigationLabel = 'Reports';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Generated')
                    ->dateTime()
                    ->sortable()
                    ->since(),
                Tables\Columns\TextColumn::make('triggered_by')
                    ->label('Triggered By')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'command' => 'info',
                        'manual'  => 'warning',
                        default   => 'gray',
                    }),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'sent'    => 'success',
                        'failed'  => 'danger',
                        'pending' => 'warning',
                        default   => 'gray',
                    }),
                Tables\Columns\TextColumn::make('recipient')
                    ->label('Recipient')
                    ->searchable(),
                Tables\Columns\TextColumn::make('summary')
                    ->label('Issues')
                    ->formatStateUsing(function ($state): string {
                        if (empty($state)) return '—';
                        $data = is_array($state) ? $state : json_decode($state, true);
                        $contentChanged = $data['content_changed'] ?? ($data['contentChanged'] ?? []);
                        $brokenAssets = $data['broken_assets'] ?? ($data['brokenAssets'] ?? []);
                        $parts = [];
                        if ($c = count($data['down'] ?? []))           $parts[] = "{$c} down";
                        if ($c = count($data['expiring'] ?? []))       $parts[] = "{$c} expiring";
                        if ($c = count($contentChanged))               $parts[] = "{$c} changed";
                        if ($c = count($brokenAssets))                 $parts[] = "{$c} broken";
                        return $parts ? implode(' · ', $parts) : 'All clear';
                    })
                    ->color(function ($state): string {
                        if (empty($state)) return 'gray';
                        $data = is_array($state) ? $state : json_decode($state, true);
                        $contentChanged = $data['content_changed'] ?? ($data['contentChanged'] ?? []);
                        $brokenAssets = $data['broken_assets'] ?? ($data['brokenAssets'] ?? []);
                        $hasIssues = count($data['down'] ?? []) + count($data['expiring'] ?? [])
                            + count($contentChanged) + count($brokenAssets);
                        return $hasIssues ? 'warning' : 'success';
                    })
                    ->badge(),
                Tables\Columns\TextColumn::make('sent_at')
                    ->label('Sent At')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('Not sent'),
                Tables\Columns\TextColumn::make('subject')
                    ->label('Subject')
                    ->limit(60)
                    ->tooltip(fn($record) => $record->subject)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'sent'    => 'Sent',
                        'failed'  => 'Failed',
                        'pending' => 'Pending',
                    ]),
                Tables\Filters\SelectFilter::make('triggered_by')
                    ->options([
                        'command' => 'Command',
                        'manual'  => 'Manual',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('resend')
                    ->label('Resend')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Resend Report')
                    ->modalDescription(fn($record) => "Resend this report to {$record->recipient}?")
                    ->action(function ($record) {
                        $report = app(MonitoringReportService::class)->send($record);

                        if ($report->isSent()) {
                            \Filament\Notifications\Notification::make()
                                ->title('Report Resent')
                                ->body("Report sent to {$report->recipient}")
                                ->success()->send();
                        } else {
                            \Filament\Notifications\Notification::make()
                                ->title('Send Failed')
                                ->body($report->error_message)
                                ->danger()->send();
                        }
                    }),
            ])
            ->headerActions([
                Tables\Actions\Action::make('prune_reports')
                    ->label('Prune Old Reports')
                    ->icon('heroicon-o-trash')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription('Delete all reports older than 30 days. This cannot be undone.')
                    ->action(function () {
                        $deleted = MonitoringReport::where('created_at', '<', now()->subDays(30))->delete();
                        \Filament\Notifications\Notification::make()
                            ->title('Reports Pruned')
                            ->body("Deleted {$deleted} report(s) older than 30 days")
                            ->success()->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function canCreate(): bool { return false; }
    public static function canEdit($record): bool { return false; }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMonitoringReports::route('/'),
            'view'  => Pages\ViewMonitoringReport::route('/{record}'),
        ];
    }
}
