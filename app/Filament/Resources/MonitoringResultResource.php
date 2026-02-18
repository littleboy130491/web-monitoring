<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MonitoringResultResource\Pages;
use App\Filament\Resources\MonitoringResultResource\RelationManagers;
use App\Models\MonitoringResult;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Storage;

class MonitoringResultResource extends Resource
{
    protected static ?string $model = MonitoringResult::class;

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'Monitoring';

    protected static ?string $navigationLabel = 'Results';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('website_id')
                    ->relationship('website', 'name')
                    ->required()
                    ->disabled(),
                Forms\Components\TextInput::make('status')
                    ->disabled(),
                Forms\Components\TextInput::make('status_code')
                    ->numeric()
                    ->disabled(),
                Forms\Components\TextInput::make('response_time')
                    ->numeric()
                    ->suffix(' ms')
                    ->disabled(),
                Forms\Components\DateTimePicker::make('checked_at')
                    ->disabled(),
                Forms\Components\Toggle::make('content_changed')
                    ->disabled(),
                Forms\Components\Textarea::make('error_message')
                    ->disabled()
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('website.url')
                    ->sortable()
                    ->searchable()
                    ->label('Website')
                    ->url(fn($record): string => $record->website->url)
                    ->openUrlInNewTab(),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'up' => 'success',
                        'down' => 'danger',
                        'error' => 'danger',
                        'warning' => 'warning',
                        default => 'gray',
                    })
                    ->searchable(),
                Tables\Columns\TextColumn::make('status_code')
                    ->badge()
                    ->color(fn(?int $state): string => match (true) {
                        $state >= 200 && $state < 300 => 'success',
                        $state >= 300 && $state < 400 => 'warning',
                        $state >= 400 => 'danger',
                        default => 'gray',
                    })
                    ->sortable()
                    ->label('HTTP Code'),
                Tables\Columns\TextColumn::make('response_time')
                    ->numeric()
                    ->sortable()
                    ->suffix(' ms')
                    ->color(fn(?int $state): string => match (true) {
                        $state < 1000 => 'success',
                        $state < 3000 => 'warning',
                        $state >= 3000 => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\IconColumn::make('scan_results.any_significant_change')
                    ->boolean()
                    ->label('Significant Content Changed')
                    ->getStateUsing(fn($record) => (bool) ($record->scan_results['any_significant_change'] ?? false))
                    ->trueColor('danger')
                    ->falseColor('success'),
                Tables\Columns\IconColumn::make('scan_results.has_broken_assets')
                    ->boolean()
                    ->label('Has Broken Assets')
                    ->getStateUsing(fn($record) => (bool) ($record->scan_results['has_broken_assets'] ?? false))
                    ->trueColor('danger')
                    ->falseColor('success'),
                Tables\Columns\ImageColumn::make('screenshot_path')
                    ->disk('public')
                    ->height(50)
                    ->width(70)
                    ->label('Screenshot')
                    ->visibility('public')
                    ->url(fn($record): string => ($record->screenshot_path ? Storage::url($record->screenshot_path) : ''))
                    ->openUrlInNewTab(),
                Tables\Columns\TextColumn::make('scan_results')
                    ->label('Scan')
                    ->formatStateUsing(function ($state, $record): string {
                        if (empty($state)) {
                            return '—';
                        }
                        $data = is_array($state) ? $state : json_decode($state, true);
                        if (!$data) return '—';

                        $pages = $data['pages'] ?? [];
                        $broken = count($data['broken_assets'] ?? []);
                        $changed = collect($pages)->where('significant', true)->count();

                        $parts = [];
                        if ($changed > 0) {
                            $parts[] = "{$changed} page(s) changed";
                        }
                        if ($broken > 0) {
                            $parts[] = "{$broken} broken asset(s)";
                        }
                        return $parts ? implode(', ', $parts) : count($pages) . ' page(s) OK';
                    })
                    ->color(function ($state, $record): string {
                        if (empty($state)) return 'gray';
                        $data = is_array($state) ? $state : json_decode($state, true);
                        if (!$data) return 'gray';
                        if ($data['any_significant_change'] ?? false) return 'warning';
                        if ($data['has_broken_assets'] ?? false) return 'warning';
                        return 'success';
                    })
                    ->badge()
                    ->tooltip(function ($state, $record): ?string {
                        if (empty($state)) return null;
                        $data = is_array($state) ? $state : json_decode($state, true);
                        if (!$data) return null;
                        $lines = [];
                        foreach ($data['pages'] ?? [] as $page) {
                            $flag = $page['significant'] ? ' ⚠' : '';
                            $lines[] = "/{$page['slug']}: {$page['change_percent']}%{$flag}";
                        }
                        foreach ($data['broken_assets'] ?? [] as $asset) {
                            $lines[] = "404: {$asset['url']}";
                        }
                        return implode("\n", $lines);
                    }),
                Tables\Columns\TextColumn::make('domain_days_until_expiry')
                    ->label('Domain Expiry')
                    ->sortable()
                    ->formatStateUsing(fn (?int $state): string => match (true) {
                        $state === null => '—',
                        $state <= 0    => 'Expired',
                        default        => "{$state}d left",
                    })
                    ->color(fn (?int $state): string => match (true) {
                        $state === null  => 'gray',
                        $state <= 0      => 'danger',
                        $state <= 7      => 'danger',
                        $state <= 30     => 'warning',
                        default          => 'success',
                    })
                    ->badge()
                    ->tooltip(fn ($record): ?string => $record->domain_expires_at?->format('Y-m-d')),
                Tables\Columns\TextColumn::make('checked_at')
                    ->dateTime()
                    ->sortable()
                    ->since(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('website')
                    ->relationship('website', 'url')
                    ->searchable()
                    ->preload(),
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'up' => 'Up',
                        'down' => 'Down',
                        'error' => 'Error',
                        'warning' => 'Warning',
                    ]),
                Tables\Filters\Filter::make('has_screenshot')
                    ->query(fn(Builder $query): Builder => $query->whereNotNull('screenshot_path'))
                    ->label('Has Screenshot'),
                Tables\Filters\Filter::make('no_screenshot')
                    ->query(fn(Builder $query): Builder => $query->whereNull('screenshot_path'))
                    ->label('No Screenshot'),
                Tables\Filters\Filter::make('last_24_hours')
                    ->query(fn(Builder $query): Builder => $query->where('checked_at', '>=', now()->subDay()))
                    ->label('Last 24 Hours'),
                Tables\Filters\Filter::make('has_broken_assets')
                    ->query(fn(Builder $query): Builder => $query->whereNotNull('scan_results')
                        ->whereRaw("json_extract(scan_results, '$.has_broken_assets') = 1"))
                    ->label('Has Broken Assets'),
                Tables\Filters\Filter::make('content_scan_changed')
                    ->query(fn(Builder $query): Builder => $query->whereNotNull('scan_results')
                        ->whereRaw("json_extract(scan_results, '$.any_significant_change') = 1"))
                    ->label('Significant Content Change'),
                Tables\Filters\Filter::make('domain_expiring_soon')
                    ->query(fn(Builder $query): Builder => $query->whereNotNull('domain_days_until_expiry')->where('domain_days_until_expiry', '<=', 7))
                    ->label('Domain Expiring ≤7 Days'),
                Tables\Filters\Filter::make('domain_expired')
                    ->query(fn(Builder $query): Builder => $query->whereNotNull('domain_days_until_expiry')->where('domain_days_until_expiry', '<=', 0))
                    ->label('Domain Expired'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMonitoringResults::route('/'),
            'view' => Pages\ViewMonitoringResult::route('/{record}'),
        ];
    }
}
