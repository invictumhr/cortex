<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|\UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            TextInput::make('email')
                ->email()
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255),
            TextInput::make('password')
                ->password()
                ->required()
                ->minLength(8)
                ->maxLength(255)
                ->visibleOn('create'),
            Toggle::make('is_admin')
                ->label('Admin')
                ->helperText('Grants access to the /admin panel.'),
            Select::make('country_code')
                ->label('Country')
                ->options(self::countries())
                ->searchable()
                ->placeholder('— Not set —'),
            TextInput::make('vat_id')
                ->label('VAT / OIB')
                ->maxLength(32),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->sortable(),
                IconColumn::make('is_admin')
                    ->label('Admin')
                    ->boolean()
                    ->sortable(),
                IconColumn::make('email_verified_at')
                    ->label('Verified')
                    ->boolean()
                    ->getStateUsing(fn (User $r) => $r->email_verified_at !== null),
                TextColumn::make('wallet.balance')
                    ->money('EUR')
                    ->label('Balance')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('country_code')
                    ->label('Country')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('chats_count')
                    ->counts('chats')
                    ->label('Chats')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('free_credit_granted_at')
                    ->label('Free credit')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_admin')
                    ->label('Role')
                    ->trueLabel('Admins only')
                    ->falseLabel('Regular users')
                    ->placeholder('All'),
                TernaryFilter::make('email_verified')
                    ->label('Email verified')
                    ->trueLabel('Verified')
                    ->falseLabel('Unverified')
                    ->placeholder('All')
                    ->queries(
                        true: fn ($q) => $q->whereNotNull('email_verified_at'),
                        false: fn ($q) => $q->whereNull('email_verified_at'),
                    ),
                SelectFilter::make('country_code')
                    ->label('Country')
                    ->options(self::countries())
                    ->searchable(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
        ];
    }

    private static function countries(): array
    {
        return [
            'HR' => 'Croatia',
            'AT' => 'Austria',  'BE' => 'Belgium',  'BG' => 'Bulgaria',  'CY' => 'Cyprus',
            'CZ' => 'Czechia',  'DE' => 'Germany',  'DK' => 'Denmark',   'EE' => 'Estonia',
            'ES' => 'Spain',    'FI' => 'Finland',  'FR' => 'France',    'GR' => 'Greece',
            'HU' => 'Hungary',  'IE' => 'Ireland',  'IT' => 'Italy',     'LT' => 'Lithuania',
            'LU' => 'Luxembourg', 'LV' => 'Latvia', 'MT' => 'Malta',     'NL' => 'Netherlands',
            'PL' => 'Poland',   'PT' => 'Portugal', 'RO' => 'Romania',   'SE' => 'Sweden',
            'SI' => 'Slovenia', 'SK' => 'Slovakia',
            'BA' => 'Bosnia and Herzegovina', 'RS' => 'Serbia', 'ME' => 'Montenegro',
            'MK' => 'North Macedonia', 'AL' => 'Albania', 'XK' => 'Kosovo',
            'CH' => 'Switzerland', 'NO' => 'Norway', 'IS' => 'Iceland',
            'GB' => 'United Kingdom', 'US' => 'United States', 'CA' => 'Canada',
            'AU' => 'Australia', 'NZ' => 'New Zealand',
        ];
    }
}
