<?php

namespace App\Filament\Resources\WebsiteResource\RelationManagers;

use App\Models\MonitoringResult;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Storage;

class MonitoringResultsRelationManager extends RelationManager
{
    protected static string $relationship = 'monitoringResults';

    public function form(Form $form): Form
    {
        return $form
            ->schema([
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

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('status')
            ->columns([
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
                Tables\Columns\IconColumn::make('content_changed')
                    ->boolean()
                    ->label('Content Changed'),
                Tables\Columns\ImageColumn::make('screenshot_path')
                    ->disk('public')
                    ->height(50)
                    ->width(70)
                    ->label('Screenshot')
                    ->visibility('public')
                    ->url(fn($record): string => ($record->screenshot_path ? Storage::url($record->screenshot_path) : ''))
                    ->openUrlInNewTab(),
                Tables\Columns\TextColumn::make('checked_at')
                    ->dateTime()
                    ->sortable()
                    ->since(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'up' => 'Up',
                        'down' => 'Down',
                        'error' => 'Error',
                        'warning' => 'Warning',
                    ]),
                Tables\Filters\Filter::make('content_changed')
                    ->query(fn(Builder $query): Builder => $query->where('content_changed', true))
                    ->label('Content Changed'),
                Tables\Filters\Filter::make('has_screenshot')
                    ->query(fn(Builder $query): Builder => $query->whereNotNull('screenshot_path'))
                    ->label('Has Screenshot'),
                Tables\Filters\Filter::make('no_screenshot')
                    ->query(fn(Builder $query): Builder => $query->whereNull('screenshot_path'))
                    ->label('No Screenshot'),
                Tables\Filters\Filter::make('last_24_hours')
                    ->query(fn(Builder $query): Builder => $query->where('checked_at', '>=', now()->subDay()))
                    ->label('Last 24 Hours'),
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
}