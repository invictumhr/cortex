<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('powershell_permissions', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_enabled')->default(false);
            $table->json('allowed_commands')->nullable();
            $table->json('blocked_paths')->nullable();
            $table->json('blocked_commands')->nullable();
            $table->unsignedInteger('max_execution_time')->default(30);
            $table->boolean('require_approval')->default(true);
            $table->boolean('log_all_commands')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('powershell_permissions');
    }
};
