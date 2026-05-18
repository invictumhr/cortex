<?php

namespace App\Filament\Resources\PowerShellPermissions\Pages;

use App\Filament\Resources\PowerShellPermissions\PowerShellPermissionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPowerShellPermissions extends ListRecords
{
    protected static string $resource = PowerShellPermissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
