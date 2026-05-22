<?php

namespace App\Filament\Resources\Wallets;

use App\Filament\Resources\Wallets\Pages\ListWallets;
use App\Models\Wallet;
use App\Services\Billing\WalletService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Admin wallet view — every user's wallet at a glance, with a one-click
 * "issue grant" action so support can hand out credits (refunds, comp-ed
 * trials, contests). Every grant lands as a GRANT row in the ledger with the
 * admin's note attached.
 */
class WalletResource extends Resource
{
    protected static ?string $model = Wallet::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')->label('User')->searchable(),
                TextColumn::make('user.email')->searchable()->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('balance')->money('EUR')->sortable(),
                TextColumn::make('reserved_balance')->money('EUR')->sortable()->label('Reserved'),
                TextColumn::make('available')
                    ->state(fn (Wallet $r) => $r->availableBalance())
                    ->money('EUR')
                    ->label('Available'),
                TextColumn::make('maintenance_flag')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state ? 'maintenance' : 'live')
                    ->color(fn ($state) => $state ? 'warning' : 'success'),
                TextColumn::make('updated_at')->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->recordActions([
                Action::make('grant')
                    ->icon(Heroicon::OutlinedGift)
                    ->color('success')
                    ->modalDescription('Adds the amount directly to the wallet as a GRANT ledger row. Use a clear reason — it shows up in the audit log forever.')
                    ->schema([
                        TextInput::make('amount')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->prefix('€')
                            ->step(0.01),
                        Textarea::make('reason')
                            ->required()
                            ->maxLength(500)
                            ->placeholder('Refund for failed run on chat #X; support waiver; etc.'),
                    ])
                    ->action(function (Wallet $record, array $data) {
                        app(WalletService::class)->grant(
                            $record,
                            (float) $data['amount'],
                            'admin: '.$data['reason'],
                            sourceType: 'admin_grant',
                            sourceId: auth()->id(),
                        );
                    }),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWallets::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
