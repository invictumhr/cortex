<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Add a SHA-256 public_id to chats so the REST API never exposes sequential
 * numeric IDs. This prevents enumeration attacks — even if ownership checks
 * catch cross-user access, a 64-char hex hash is unguessable by construction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->string('public_id', 64)->nullable()->unique()->after('id');
        });

        // Backfill existing chats with unique hashes.
        foreach (DB::table('chats')->pluck('id') as $id) {
            DB::table('chats')->where('id', $id)->update([
                'public_id' => hash('sha256', Str::random(40).$id),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropColumn('public_id');
        });
    }
};
