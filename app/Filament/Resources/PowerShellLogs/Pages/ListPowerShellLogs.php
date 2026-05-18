<?php

namespace App\Filament\Resources\PowerShellLogs\Pages;

use App\Filament\Resources\PowerShellLogs\PowerShellLogResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPowerShellLogs extends ListRecords
{
    protected static string $resource = PowerShellLogResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
