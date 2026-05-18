<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_personas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained('chats')->cascadeOnDelete();
            $table->foreignId('persona_id')->constrained('personas')->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->decimal('cost', 10, 6)->default(0);
            $table->timestamps();

            $table->unique(['chat_id', 'persona_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_personas');
    }
};
