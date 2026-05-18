<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scribe_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained('chats')->cascadeOnDelete();
            $table->foreignId('persona_id')->nullable()->constrained('personas')->nullOnDelete();
            $table->foreignId('from_message_id')->nullable()->constrained('chat_messages')->nullOnDelete();
            $table->foreignId('to_message_id')->nullable()->constrained('chat_messages')->nullOnDelete();
            $table->longText('summary');
            $table->json('key_decisions')->nullable();
            $table->json('key_ideas')->nullable();
            $table->json('open_questions')->nullable();
            $table->json('action_items')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost', 10, 6)->default(0);
            $table->timestamps();

            $table->index('chat_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scribe_summaries');
    }
};
