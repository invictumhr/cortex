<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('powershell_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_id')->nullable()->constrained('chats')->nullOnDelete();
            $table->foreignId('persona_id')->nullable()->constrained('personas')->nullOnDelete();
            $table->text('command');
            $table->longText('output')->nullable();
            $table->integer('exit_code')->nullable();
            $table->unsignedInteger('execution_time_ms')->nullable();
            $table->boolean('was_approved')->default(false);
            $table->boolean('was_blocked')->default(false);
            $table->string('block_reason')->nullable();
            $table->timestamps();

            $table->index('chat_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('powershell_logs');
    }
};
