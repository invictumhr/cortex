<?php

namespace App\Filament\Resources\PowerShellLogs;

use App\Filament\Resources\PowerShellLogs\Pages\CreatePowerShellLog;
use App\Filament\Resources\PowerShellLogs\Pages\EditPowerShellLog;
use App\Filament\Resources\PowerShellLogs\Pages\ListPowerShellLogs;
use App\Filament\Resources\PowerShellLogs\Schemas\PowerShellLogForm;
use App\Filament\Resources\PowerShellLogs\Tables\PowerShellLogsTable;
use App\Models\PowerShellLog;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PowerShellLogResource extends Resource
{
    protected static ?string $model = PowerShellLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return PowerShellLogForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PowerShellLogsTable::configure($table);
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
            'index' => ListPowerShellLogs::route('/'),
            'create' => CreatePowerShellLog::route('/create'),
            'edit' => EditPowerShellLog::route('/{record}/edit'),
        ];
    }
}
