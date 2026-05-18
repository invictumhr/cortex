<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_provider_id')->constrained('ai_providers')->cascadeOnDelete();
            $table->string('name');
            $table->string('model_string');
            $table->boolean('supports_vision')->default(false);
            $table->boolean('supports_tools')->default(false);
            $table->unsignedInteger('max_context_tokens')->default(128000);
            $table->decimal('input_cost_per_1m_tokens', 10, 4)->default(0);
            $table->decimal('output_cost_per_1m_tokens', 10, 4)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_models');
    }
};
