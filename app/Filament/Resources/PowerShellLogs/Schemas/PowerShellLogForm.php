<?php

namespace App\Filament\Resources\PowerShellLogs\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PowerShellLogForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('chat_id')
                    ->relationship('chat', 'title'),
                Select::make('persona_id')
                    ->relationship('persona', 'name'),
                Textarea::make('command')
                    ->required()
                    ->columnSpanFull(),
                Textarea::make('output')
                    ->columnSpanFull(),
                TextInput::make('exit_code')
                    ->numeric(),
                TextInput::make('execution_time_ms')
                    ->numeric(),
                Toggle::make('was_approved')
                    ->required(),
                Toggle::make('was_blocked')
                    ->required(),
                TextInput::make('block_reason'),
            ]);
    }
}
