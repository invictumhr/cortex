<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personas', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('title');
            $table->string('avatar_color')->default('#6366f1');
            $table->string('avatar_emoji')->default('🤖');
            $table->text('description');
            $table->longText('system_prompt');
            $table->foreignId('ai_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->json('personality_traits')->nullable();
            $table->json('expertise_areas')->nullable();
            $table->string('communication_style')->default('casual');
            $table->boolean('is_scribe')->default(false);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personas');
    }
};
