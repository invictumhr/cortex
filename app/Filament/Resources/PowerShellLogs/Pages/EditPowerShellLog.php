<?php

namespace App\Filament\Resources\PowerShellLogs\Pages;

use App\Filament\Resources\PowerShellLogs\PowerShellLogResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPowerShellLog extends EditRecord
{
    protected static string $resource = PowerShellLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
