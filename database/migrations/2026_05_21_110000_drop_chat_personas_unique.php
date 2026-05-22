<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the unique index on (chat_id, persona_id) in chat_personas.
 *
 * The original pivot assumed a persona could appear at most once per chat —
 * fine when personas were 1:1 with models. Now that `chat_personas.ai_model_id`
 * carries the per-pair model override (custom init mode), users can
 * intentionally seat the same persona twice with two different models for
 * head-to-head testing. The model-aware pivot row needs its own identity.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The FK on chat_id is currently propped up by the unique index —
        // dropping it directly fails with MySQL 1553. Add the replacement
        // (non-unique) index FIRST so the FK has something to lean on, THEN
        // drop the unique. Two separate Schema::table calls because MySQL
        // can't add+drop indexes covering the same columns in one ALTER.
        Schema::table('chat_personas', function (Blueprint $table) {
            $table->index(['chat_id', 'persona_id'], 'chat_personas_chat_persona_idx');
        });

        Schema::table('chat_personas', function (Blueprint $table) {
            $table->dropUnique('chat_personas_chat_id_persona_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('chat_personas', function (Blueprint $table) {
            $table->unique(['chat_id', 'persona_id'], 'chat_personas_chat_id_persona_id_unique');
        });

        Schema::table('chat_personas', function (Blueprint $table) {
            $table->dropIndex('chat_personas_chat_persona_idx');
        });
    }
};
