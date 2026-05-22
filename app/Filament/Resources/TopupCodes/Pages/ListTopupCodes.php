<?php

namespace App\Filament\Resources\TopupCodes\Pages;

use App\Filament\Resources\TopupCodes\TopupCodeResource;
use Filament\Resources\Pages\ListRecords;

class ListTopupCodes extends ListRecords
{
    protected static string $resource = TopupCodeResource::class;
}
