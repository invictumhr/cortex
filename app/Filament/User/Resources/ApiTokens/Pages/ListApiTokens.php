<?php

namespace App\Filament\User\Resources\ApiTokens\Pages;

use App\Filament\User\Resources\ApiTokens\ApiTokenResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListApiTokens extends ListRecords
{
    protected static string $resource = ApiTokenResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
