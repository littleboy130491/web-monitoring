<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WebsiteResource\Pages;
use App\Filament\Resources\WebsiteResource\RelationManagers;
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
                Forms\Components\TextInput::make('name')
                    ->required(),
                Forms\Components\TextInput::make('url')
                    ->required()
                    ->url()
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('description')
                    ->columnSpanFull(),
                Forms\Components\Toggle::make('is_active')
                    ->required(),
                Forms\Components\TextInput::make('check_interval')
                    ->required()
                    ->numeric()
                    ->default(300)
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
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
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
                    ->color(fn (string $state): string => match ($state) {
                        'up' => 'success',
                        'down' => 'danger', 
                        'error' => 'danger',
                        'warning' => 'warning',
                        default => 'gray',
                    })
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
                    ->color('primary')
                    ->url(fn (Website $record): string => route('monitor.website.get', $record))
                    ->openUrlInNewTab(false),
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
            //
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
