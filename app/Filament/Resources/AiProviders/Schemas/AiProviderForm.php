<?php

namespace App\Filament\Resources\AiProviders\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class AiProviderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('slug')
                    ->required(),
                Textarea::make('api_key')
                    ->columnSpanFull(),
                TextInput::make('api_base_url')
                    ->url(),
                Toggle::make('is_active')
                    ->required(),
                TextInput::make('settings'),
            ]);
    }
}
