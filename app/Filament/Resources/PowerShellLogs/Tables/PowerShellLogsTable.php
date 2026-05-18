<?php

namespace App\Filament\Resources\PowerShellLogs\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PowerShellLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('chat.title')
                    ->searchable(),
                TextColumn::make('persona.name')
                    ->searchable(),
                TextColumn::make('exit_code')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('execution_time_ms')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('was_approved')
                    ->boolean(),
                IconColumn::make('was_blocked')
                    ->boolean(),
                TextColumn::make('block_reason')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
