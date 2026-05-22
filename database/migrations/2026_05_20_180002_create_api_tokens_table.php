<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal access tokens for the public API. Stored as SHA-256 hashes so a
 * leaked database doesn't leak working tokens. Scopes are kept as JSON so we
 * can add new ones later without migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // sha256 hash — 64 hex chars. Plaintext token is shown to the user
            // once at creation and never stored.
            $table->string('token_hash', 64);
            $table->string('label', 100);
            // ['cortex:discuss', 'cortex:knowledge', 'cortex:read'] or null = all.
            $table->json('scopes')->nullable();
            $table->timestamp('last_used_at')->nullable();
            // Soft revocation — keeps usage history attached even after the
            // user nukes the token.
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'token_hash']);
            // Lookup path: incoming request → hash header → find token row.
            $table->index('token_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
