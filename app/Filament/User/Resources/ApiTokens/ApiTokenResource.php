<?php

namespace App\Filament\User\Resources\ApiTokens;

use App\Filament\User\Resources\ApiTokens\Pages\CreateApiToken;
use App\Filament\User\Resources\ApiTokens\Pages\ListApiTokens;
use App\Models\ApiToken;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * User-facing API token management. Plaintext is shown once at creation and
 * stored as a sha256 hash; the table lists labels, scopes, last use, and a
 * revoke action.
 */
class ApiTokenResource extends Resource
{
    protected static ?string $model = ApiToken::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|\UnitEnum|null $navigationGroup = 'Developers';

    protected static ?string $navigationLabel = 'API Tokens';

    protected static ?string $modelLabel = 'API Token';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('user_id', auth()->id())
            ->orderByDesc('created_at');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('label')
                ->required()
                ->maxLength(100)
                ->helperText('A name only you see — e.g. "n8n workflow" or "VS Code"'),
            CheckboxList::make('scopes')
                // Pulled from ApiToken::availableScopes() so the model stays
                // the single source of truth for what scopes exist.
                ->options(ApiToken::availableScopes())
                ->columns(1)
                ->helperText('Leave empty to grant all current and future scopes.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')->searchable(),
                TextColumn::make('scopes')
                    ->formatStateUsing(fn ($state) => empty($state) ? 'All' : implode(', ', (array) $state))
                    ->badge(),
                TextColumn::make('last_used_at')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('Never used')
                    ->sortable(),
                TextColumn::make('revoked_at')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('Active')
                    ->color(fn ($state) => $state ? 'danger' : 'success')
                    ->label('Status'),
                TextColumn::make('created_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('revoke')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('The token will stop working immediately. Existing chats it created stay accessible.')
                    ->visible(fn (ApiToken $record) => $record->revoked_at === null)
                    ->action(fn (ApiToken $record) => $record->update(['revoked_at' => now()])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApiTokens::route('/'),
            'create' => CreateApiToken::route('/create'),
        ];
    }
}
