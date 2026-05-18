<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_message_id')->constrained('chat_messages')->cascadeOnDelete();
            $table->string('type');
            $table->string('file_path')->nullable();
            $table->text('url')->nullable();
            $table->longText('extracted_content')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->timestamps();

            $table->index('chat_message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_attachments');
    }
};
