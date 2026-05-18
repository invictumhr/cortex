<?php

namespace App\Filament\Resources\PowerShellPermissions\Pages;

use App\Filament\Resources\PowerShellPermissions\PowerShellPermissionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPowerShellPermission extends EditRecord
{
    protected static string $resource = PowerShellPermissionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
