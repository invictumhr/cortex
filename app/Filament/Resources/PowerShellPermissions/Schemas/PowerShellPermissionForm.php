<?php

namespace App\Filament\Resources\PowerShellPermissions\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PowerShellPermissionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Toggle::make('is_enabled')
                    ->required(),
                TextInput::make('allowed_commands'),
                TextInput::make('blocked_paths'),
                TextInput::make('blocked_commands'),
                TextInput::make('max_execution_time')
                    ->required()
                    ->numeric()
                    ->default(30),
                Toggle::make('require_approval')
                    ->required(),
                Toggle::make('log_all_commands')
                    ->required(),
            ]);
    }
}
