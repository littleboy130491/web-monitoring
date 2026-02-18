<?php

namespace App\Filament\Resources;

use App\Filament\Actions\MonitoringActions;
use App\Filament\Resources\WebsiteResource\Pages;
use App\Filament\Resources\WebsiteResource\RelationManagers;
use App\Filament\Resources\WebsiteResource\RelationManagers\MonitoringResultsRelationManager;
use App\Models\Website;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class WebsiteResource extends Resource
{
    protected static ?string $model = Website::class;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationGroup = 'Monitoring';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('url')
                    ->required()
                    ->url()
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('description')
                    ->columnSpanFull(),
                Forms\Components\Toggle::make('is_active')
                    ->default(true),
                Forms\Components\TextInput::make('check_interval')
                    ->numeric()
                    ->default(3600)
                    ->suffix('seconds')
                    ->helperText('How often to check this website (in seconds)'),
                Forms\Components\KeyValue::make('headers')
                    ->keyLabel('Header Name')
                    ->valueLabel('Header Value')
                    ->columnSpanFull()
                    ->helperText('Custom HTTP headers to send with request'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('url')
                    ->searchable()
                    ->limit(50)
                    ->tooltip(function (Tables\Columns\TextColumn $column): ?string {
                        $state = $column->getState();
                        return strlen($state) > 50 ? $state : null;
                    }),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active'),
                Tables\Columns\TextColumn::make('check_interval')
                    ->numeric()
                    ->sortable()
                    ->suffix(' sec'),
                Tables\Columns\TextColumn::make('latestResult.status')
                    ->badge()
                    ->color(fn(?string $state): string => match ($state) {
                        'up' => 'success',
                        'down' => 'danger',
                        'error' => 'danger',
                        'warning' => 'warning',
                        null => 'gray',
                        default => 'gray',
                    })
                    ->default('No data')
                    ->label('Status'),
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
                //
            ])
            ->actions([
                Tables\Actions\Action::make('monitor')
                    ->label('Monitor')
                    ->icon('heroicon-o-play')
                    ->color('success')
                    ->action(fn(Website $record) => MonitoringActions::dispatchMonitor($record))
                    ->requiresConfirmation()
                    ->modalHeading('Monitor Website')
                    ->modalDescription(fn(Website $record) => "Queue monitoring for '{$record->url}'?")
                    ->modalSubmitActionLabel('Yes, Monitor'),
                Tables\Actions\Action::make('monitor_with_screenshot')
                    ->label('Monitor + Screenshot')
                    ->icon('heroicon-o-camera')
                    ->color('warning')
                    ->action(fn(Website $record) => MonitoringActions::dispatchMonitor($record, true))
                    ->requiresConfirmation()
                    ->modalHeading('Monitor Website with Screenshot')
                    ->modalDescription(fn(Website $record) => "Queue monitoring with screenshot for '{$record->url}'? This may take longer.")
                    ->modalSubmitActionLabel('Yes, Monitor with Screenshot'),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\MonitoringResultsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWebsites::route('/'),
            'create' => Pages\CreateWebsite::route('/create'),
            'edit' => Pages\EditWebsite::route('/{record}/edit'),
        ];
    }
}
