<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Event-sourced ledger for every wallet mutation. Sum of `amount` per wallet
 * must always equal `wallets.balance + wallets.reserved_balance` — daily
 * reconciliation enforces this. Every row is append-only; refunds and
 * releases are new rows that point back to their parent via `reserved_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            // DEPOSIT: top-up (Paddle). RESERVE: pre-flight hold for a boardroom run.
            // RELEASE: rollback of a RESERVE without spending. DEBIT: actual spend
            // after run completes. REFUND: return part of a RESERVE that wasn't spent.
            // GRANT: free credit (signup bonus, support). ADJUSTMENT: admin override.
            $table->enum('type', ['DEPOSIT', 'RESERVE', 'RELEASE', 'DEBIT', 'REFUND', 'GRANT', 'ADJUSTMENT']);
            // Signed impact on TOTAL user funds (balance + reserved_balance).
            // GRANT/DEPOSIT/REFUND/ADJUSTMENT add (+); DEBIT subtracts (-);
            // RESERVE/RELEASE leave total unchanged (0) — they just move money
            // between the two bins, recorded via `reserved_delta` below.
            //
            // Invariants the daily reconciliation enforces:
            //   balance + reserved_balance == sum(amount)
            //   reserved_balance           == sum(reserved_delta)
            $table->decimal('amount', 12, 6);
            // Signed impact on `reserved_balance` only. RESERVE +X, RELEASE -X,
            // DEBIT-that-settles-a-RESERVE -X (the held funds become spent),
            // everything else 0.
            $table->decimal('reserved_delta', 12, 6)->default(0);
            // What caused this transaction (polymorphic-style without the morph
            // overhead — we read these as plain strings, not Eloquent relations).
            // e.g. ('chat', 75), ('paddle_transaction', 'evt_abc123'), ('admin_grant', 1).
            $table->string('source_type', 64)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            // Refunds/releases point back to their parent RESERVE row.
            $table->foreignId('reserved_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
            // Refunds of a DEBIT row point here (admin-initiated refunds).
            $table->foreignId('parent_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
            // External payment ref (Paddle/Stripe transaction id) — indexed for
            // webhook idempotency lookups so we never apply the same deposit twice.
            $table->string('payment_source_ref', 128)->nullable();
            // Price snapshot at reserve time so subsequent debits use the rate the
            // user implicitly agreed to, even if provider prices change mid-run.
            $table->decimal('rate_input_snapshot', 10, 6)->nullable();
            $table->decimal('rate_output_snapshot', 10, 6)->nullable();
            $table->foreignId('provider_id')->nullable()->constrained('ai_providers')->nullOnDelete();
            // Split so admin can chart margin in one GROUP BY without joining
            // anything: provider_cost = what the API actually cost us in EUR;
            // user_cost = what we billed the user (provider_cost × margin multiplier).
            $table->decimal('provider_cost', 12, 6)->nullable();
            $table->decimal('user_cost', 12, 6)->nullable();
            // Free-form context: paddle tx details, admin note, anomaly score, etc.
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['wallet_id', 'type']);
            $table->index(['source_type', 'source_id']);
            $table->index('payment_source_ref');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
