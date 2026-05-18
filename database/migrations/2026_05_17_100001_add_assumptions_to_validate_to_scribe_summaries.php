<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scribe_summaries', function (Blueprint $table) {
            $table->json('assumptions_to_validate')->nullable()->after('action_items');
        });
    }

    public function down(): void
    {
        Schema::table('scribe_summaries', function (Blueprint $table) {
            $table->dropColumn('assumptions_to_validate');
        });
    }
};
