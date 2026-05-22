<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wallet ledger root — one wallet per user. Holds spendable balance and a
 * frozen `reserved_balance` for in-flight boardroom estimates so two parallel
 * runs can't double-spend the same euros.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            // 6 decimals so we can record sub-cent provider costs faithfully.
            $table->decimal('balance', 12, 6)->default(0);
            $table->decimal('reserved_balance', 12, 6)->default(0);
            $table->char('currency', 3)->default('EUR');
            // Set during the legacy chat → wallet backfill so live debits skip
            // a wallet that's still being reconciled.
            $table->boolean('maintenance_flag')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallets');
    }
};
