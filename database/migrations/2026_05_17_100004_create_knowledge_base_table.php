<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_base', function (Blueprint $table) {
            $table->id();
            $table->longText('digest')->nullable();
            $table->unsignedInteger('notes_count')->default(0);
            $table->timestamp('digested_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_base');
    }
};
