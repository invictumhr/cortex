<?php

namespace App\Filament\Resources\PowerShellPermissions;

use App\Filament\Resources\PowerShellPermissions\Pages\CreatePowerShellPermission;
use App\Filament\Resources\PowerShellPermissions\Pages\EditPowerShellPermission;
use App\Filament\Resources\PowerShellPermissions\Pages\ListPowerShellPermissions;
use App\Filament\Resources\PowerShellPermissions\Schemas\PowerShellPermissionForm;
use App\Filament\Resources\PowerShellPermissions\Tables\PowerShellPermissionsTable;
use App\Models\PowerShellPermission;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PowerShellPermissionResource extends Resource
{
    protected static ?string $model = PowerShellPermission::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return PowerShellPermissionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PowerShellPermissionsTable::configure($table);
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
            'index' => ListPowerShellPermissions::route('/'),
            'create' => CreatePowerShellPermission::route('/create'),
            'edit' => EditPowerShellPermission::route('/{record}/edit'),
        ];
    }
}
