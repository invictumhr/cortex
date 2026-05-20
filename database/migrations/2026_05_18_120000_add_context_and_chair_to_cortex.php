<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->text('context')->nullable()->after('description');
            $table->text('constraints')->nullable()->after('context');
        });

        Schema::table('personas', function (Blueprint $table) {
            $table->boolean('is_chair')->default(false)->after('is_scribe');
        });
    }

    public function down(): void
    {
        Schema::table('chats', function (Blueprint $table) {
            $table->dropColumn(['context', 'constraints']);
        });

        Schema::table('personas', function (Blueprint $table) {
            $table->dropColumn('is_chair');
        });
    }
};
