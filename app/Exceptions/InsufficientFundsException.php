<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by WalletService::reserve when the wallet's available balance
 * (balance − reserved) is below the requested amount. Caller decides whether
 * to surface this as a 402 to the API, a UI prompt for top-up, or a system
 * message in the chat.
 */
class InsufficientFundsException extends RuntimeException
{
    public function __construct(
        public readonly int $walletId,
        public readonly float $available,
        public readonly float $requested,
    ) {
        parent::__construct(sprintf(
            'Wallet #%d insufficient funds: available €%.6f, requested €%.6f',
            $walletId, $available, $requested,
        ));
    }
}
