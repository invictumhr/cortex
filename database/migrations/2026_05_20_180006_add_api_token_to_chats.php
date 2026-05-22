<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stamp which API token (if any) kicked off the chat — so the user's panel
 * can show "Token X started Y chats this month" without a join through
 * api_token_usages. Null = started from web UI or CLI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->foreignId('initiated_by_token_id')->nullable()->after('user_id')->constrained('api_tokens')->nullOnDelete();

            $table->index('initiated_by_token_id');
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropForeign(['initiated_by_token_id']);
            $table->dropIndex(['initiated_by_token_id']);
            $table->dropColumn('initiated_by_token_id');
        });
    }
};
