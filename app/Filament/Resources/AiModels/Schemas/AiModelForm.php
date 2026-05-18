<?php

namespace App\Filament\Resources\AiModels\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class AiModelForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('ai_provider_id')
                    ->required()
                    ->numeric(),
                TextInput::make('name')
                    ->required(),
                TextInput::make('model_string')
                    ->required(),
                Toggle::make('supports_vision')
                    ->required(),
                Toggle::make('supports_tools')
                    ->required(),
                TextInput::make('max_context_tokens')
                    ->required()
                    ->numeric()
                    ->default(128000),
                TextInput::make('input_cost_per_1m_tokens')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                TextInput::make('output_cost_per_1m_tokens')
                    ->required()
                    ->numeric()
                    ->default(0.0),
                Toggle::make('is_active')
                    ->required(),
            ]);
    }
}
