<?php

namespace App\Filament\User\Resources\Wallet;

use App\Filament\User\Resources\Wallet\Pages\ListWalletTransactions;
use App\Models\WalletTransaction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * User-facing read-only view of their wallet ledger. Filterable by transaction
 * type so a customer can pull just deposits for accounting, or just debits to
 * see where their credits went.
 */
class WalletTransactionResource extends Resource
{
    protected static ?string $model = WalletTransaction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyEuro;

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?string $navigationLabel = 'Transactions';

    protected static ?string $modelLabel = 'Transaction';

    protected static ?int $navigationSort = 2;

    /**
     * Scope every query to the current user's wallet — Filament's default
     * resource query is the model's all-records query, which would leak other
     * users' ledger rows.
     */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();
        $walletId = $user?->wallet?->id;

        return parent::getEloquentQuery()
            ->when($walletId, fn ($q) => $q->where('wallet_id', $walletId), fn ($q) => $q->whereRaw('1=0'));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->label('Date'),
                TextColumn::make('type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'DEPOSIT' => 'success',
                        'GRANT' => 'info',
                        'REFUND' => 'warning',
                        'DEBIT' => 'danger',
                        'RESERVE' => 'gray',
                        'RELEASE' => 'gray',
                        default => 'secondary',
                    })
                    ->sortable(),
                TextColumn::make('amount')
                    ->money('EUR')
                    ->color(fn (float $state) => $state >= 0 ? 'success' : 'danger')
                    ->sortable()
                    ->label('Net change'),
                TextColumn::make('reserved_delta')
                    ->money('EUR')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label('Reserved Δ'),
                TextColumn::make('user_cost')
                    ->money('EUR')
                    ->placeholder('—')
                    ->label('Billed'),
                TextColumn::make('source_type')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('source_id')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('payment_source_ref')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->label('Payment ref'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('type')->options([
                    'DEPOSIT' => 'Deposit',
                    'GRANT' => 'Grant',
                    'REFUND' => 'Refund',
                    'DEBIT' => 'Debit',
                    'RESERVE' => 'Reserve',
                    'RELEASE' => 'Release',
                    'ADJUSTMENT' => 'Adjustment',
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWalletTransactions::route('/'),
        ];
    }

    /** No record creation from the user panel — wallet mutations only flow
     *  through WalletService (deposit webhook, persona debit, etc.). */
    public static function canCreate(): bool
    {
        return false;
    }
}
