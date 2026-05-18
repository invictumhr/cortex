<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->constrained('chats')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->json('helpful_ideas')->nullable();
            $table->string('source')->default('cli');
            $table->timestamps();

            $table->index('chat_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_feedback');
    }
};
