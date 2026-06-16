<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `payment_source_ref` becomes UNIQUE. The deposit idempotency check in
 * WalletService::deposit() is read-then-insert — two concurrent deliveries
 * of the same webhook (or a double-submitted PIN redeem) could both pass
 * the read and double-credit the wallet. A unique index makes the database
 * the final arbiter; MySQL and SQLite both allow multiple NULLs in a unique
 * index, so rows without a payment ref are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropIndex(['payment_source_ref']);
            $table->unique('payment_source_ref');
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropUnique(['payment_source_ref']);
            $table->index('payment_source_ref');
        });
    }
};
