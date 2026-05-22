<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Code generation jobs — a "batch" is one admin-triggered run that mints N
 * PINs at the same face value. The batch row carries the operational metadata
 * (label, who issued it, when, free-form notes) so the codes themselves can
 * stay narrow. Codes link back via topup_codes.batch_id.
 *
 * Once created, batches are append-only audit records — the row stays around
 * even if every code is redeemed, so the admin always has a history of
 * "we issued these N codes for this purpose on this day".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('topup_code_batches', function (Blueprint $table) {
            $table->id();
            // Human label shown in the list — e.g. "SMS Q2 2026", "promo Slack",
            // "Reseller Croatia April". Required so every batch has SOMETHING
            // identifiable; admin can rename later if needed.
            $table->string('label', 120);
            // Face value per code. All codes in a batch share the same amount —
            // mixing values inside one batch would defeat the point of batches.
            $table->decimal('amount_per_code', 12, 6);
            // How many codes the batch was asked to mint. Stored explicitly
            // (rather than count(codes)) so we can show "12 of 50 issued" if a
            // generation ever partially fails.
            $table->unsignedInteger('code_count');
            // Free-form admin notes (distribution channel, recipient list ref,
            // SMS provider job id, etc.).
            $table->text('notes')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('topup_code_batches');
    }
};
