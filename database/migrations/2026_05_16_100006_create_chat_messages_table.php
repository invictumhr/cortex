<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained('chats')->cascadeOnDelete();
            $table->foreignId('persona_id')->nullable()->constrained('personas')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role');
            $table->longText('content');
            $table->unsignedInteger('round_number')->default(0);
            $table->unsignedInteger('turn_number')->default(0);
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->decimal('cost', 10, 6)->nullable();
            $table->string('model_used')->nullable();
            $table->string('provider_used')->nullable();
            $table->unsignedInteger('response_time_ms')->nullable();
            $table->boolean('has_attachments')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['chat_id', 'round_number']);
            $table->index(['chat_id', 'turn_number']);
            $table->index('persona_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
