<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_feedback', function (Blueprint $table) {
            $table->json('used_ideas')->nullable()->after('helpful_ideas');
        });
    }

    public function down(): void
    {
        Schema::table('chat_feedback', function (Blueprint $table) {
            $table->dropColumn('used_ideas');
        });
    }
};
