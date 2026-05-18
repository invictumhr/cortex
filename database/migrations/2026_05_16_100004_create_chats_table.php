<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('rounds_per_turn')->default(1);
            $table->unsignedInteger('current_round')->default(0);
            $table->unsignedInteger('total_messages')->default(0);
            $table->unsignedBigInteger('total_input_tokens')->default(0);
            $table->unsignedBigInteger('total_output_tokens')->default(0);
            $table->decimal('total_cost', 12, 6)->default(0);
            $table->string('status')->default('active');
            $table->timestamp('last_scribe_summary_at')->nullable();
            $table->unsignedInteger('scribe_interval')->default(50);
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chats');
    }
};
