<?php

namespace App\Filament\User\Resources\Wallet\Pages;

use App\Filament\User\Resources\Wallet\WalletTransactionResource;
use Filament\Resources\Pages\ListRecords;

class ListWalletTransactions extends ListRecords
{
    protected static string $resource = WalletTransactionResource::class;
}
