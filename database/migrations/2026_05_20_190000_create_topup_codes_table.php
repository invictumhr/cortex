<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pre-paid top-up codes — 14-digit PINs delivered to the user by SMS (so we
 * don't need a payment processor or VAT invoicing for the MVP). Admin
 * generates a batch, the PINs are surfaced ONCE in plaintext to the admin,
 * and only the sha256 hash is stored. The user types the PIN in their panel
 * and the matching code is consumed (status flips to redeemed) and the
 * configured euro amount is deposited into their wallet via WalletService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topup_codes', function (Blueprint $table) {
            $table->id();
            // sha256 of the 14-digit PIN. Plaintext is shown once at
            // generation time and never written to disk again — the same
            // pattern api_tokens uses.
            $table->string('code_hash', 64)->unique();
            $table->decimal('amount', 12, 6);
            // Free-text label so admin can group codes by batch ("SMS Q1 2026",
            // "promo Slack", etc). Indexed for list filters.
            $table->string('batch_label', 64)->nullable();
            // Set on successful redeem — null while the code is still alive.
            $table->timestamp('redeemed_at')->nullable();
            $table->foreignId('redeemed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            // Hashed (sha256) — lets us spot one IP/account farming codes
            // without keeping raw PII.
            $table->string('redeemed_ip_hash', 64)->nullable();
            // Optional: which DEPOSIT wallet_transaction row this redeem
            // produced — straight audit jump from code → ledger.
            $table->foreignId('wallet_transaction_id')->nullable()->constrained()->nullOnDelete();
            // Free-form admin notes (e.g. SMS provider tx id, distribution
            // channel, internal owner). JSON so we don't keep ALTERing this
            // table as the operational shape evolves.
            $table->json('metadata')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('batch_label');
            $table->index('redeemed_at');
            $table->index('redeemed_by_user_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topup_codes');
    }
};
