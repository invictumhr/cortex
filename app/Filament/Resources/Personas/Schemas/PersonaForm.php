<?php

namespace App\Filament\Resources\Personas\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class PersonaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                TextInput::make('slug')
                    ->required(),
                TextInput::make('title')
                    ->required(),
                TextInput::make('avatar_color')
                    ->required()
                    ->default('#6366f1'),
                TextInput::make('avatar_emoji')
                    ->required()
                    ->default('?'),
                Textarea::make('description')
                    ->required()
                    ->columnSpanFull(),
                Textarea::make('system_prompt')
                    ->required()
                    ->columnSpanFull(),
                Select::make('ai_model_id')
                    ->relationship('aiModel', 'name'),
                TextInput::make('personality_traits'),
                TextInput::make('expertise_areas'),
                TextInput::make('communication_style')
                    ->required()
                    ->default('casual'),
                Toggle::make('is_scribe')
                    ->required(),
                Toggle::make('is_active')
                    ->required(),
                TextInput::make('sort_order')
                    ->required()
                    ->numeric()
                    ->default(0),
            ]);
    }
}
