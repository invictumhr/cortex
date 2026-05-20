<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Web-created chats run as a continuous boardroom (loops until the user
 * pauses); CLI chats stay bounded by rounds_per_turn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->boolean('continuous')->default(false)->after('rounds_per_turn');
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropColumn('continuous');
        });
    }
};
