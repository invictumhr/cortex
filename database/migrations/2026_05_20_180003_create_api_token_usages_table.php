<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-API-call usage log — one row per request. Lets the user see "this token
 * cost me €X this month" without scanning chat history. Status field
 * distinguishes billable calls from rate-limited/error attempts so we don't
 * count those against the user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_token_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_token_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chat_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('chat_message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('endpoint', 100)->nullable();
            // Mirror of the WalletTransaction split so per-token margin is also
            // a trivial GROUP BY (no join to wallet_transactions required for
            // the most common dashboard queries).
            $table->decimal('provider_cost', 12, 6)->nullable();
            $table->decimal('user_cost', 12, 6)->nullable();
            $table->integer('response_time_ms')->nullable();
            $table->enum('status', ['ok', 'rate_limited', 'insufficient_funds', 'error']);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['api_token_id', 'created_at']);
            $table->index('chat_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_token_usages');
    }
};
