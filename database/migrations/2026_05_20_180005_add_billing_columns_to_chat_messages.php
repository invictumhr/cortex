<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pin every persona message to its billing footprint: was it billable, what
 * did it cost us, what did we bill the user, and which wallet transaction
 * carries the debit. `cost` (legacy) stays as provider_cost for historical
 * compatibility; new columns add the user-facing amount and audit link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            // false until PersonaResponder confirms output_tokens > 0,
            // finish_reason == 'stop', and the provider didn't error.
            // Anything else is logged but not charged.
            $table->boolean('is_billable')->default(false)->after('cost');
            // Provider EUR cost (mirror of legacy `cost` for new rows, kept
            // separate so the legacy column can stay untouched for backfill).
            $table->decimal('provider_cost', 12, 6)->nullable()->after('is_billable');
            // What we actually charged the user (provider_cost × margin
            // multiplier at debit time — tier-aware).
            $table->decimal('user_cost', 12, 6)->nullable()->after('provider_cost');
            // 'stop' = complete, 'length' = hit max_tokens (partial bill),
            // 'content_filter' = refused (no bill), null = unknown / not received.
            $table->string('finish_reason', 32)->nullable()->after('user_cost');
            // Audit link: the DEBIT row in wallet_transactions that paid for this
            // message. Lets admin click from a chat message straight to the
            // ledger entry — and conversely lets reconciliation join both ways.
            $table->foreignId('wallet_transaction_id')->nullable()->after('finish_reason')->constrained()->nullOnDelete();

            $table->index('is_billable');
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropForeign(['wallet_transaction_id']);
            $table->dropIndex(['is_billable']);
            $table->dropColumn(['is_billable', 'provider_cost', 'user_cost', 'finish_reason', 'wallet_transaction_id']);
        });
    }
};
