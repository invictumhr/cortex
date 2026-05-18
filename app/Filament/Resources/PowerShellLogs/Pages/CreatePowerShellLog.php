<?php

namespace App\Filament\Resources\PowerShellLogs\Pages;

use App\Filament\Resources\PowerShellLogs\PowerShellLogResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePowerShellLog extends CreateRecord
{
    protected static string $resource = PowerShellLogResource::class;
}
