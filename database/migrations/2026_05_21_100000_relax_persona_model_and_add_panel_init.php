<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loosen the persona ↔ model coupling so a persona is a character template
 * and the model is picked at panel-composition time. Add three init modes
 * for new chats:
 *
 *   quick           — user picked a head-count; system auto-picks personas
 *                     AND models after the first message lands
 *   custom_models   — user picked exact models; system auto-picks personas
 *                     after the first message
 *   custom          — user hand-picked (persona, model) pairs at creation
 *
 * `chat_personas.ai_model_id` is the per-chat per-persona model override
 * (null = use the persona's default `ai_model_id`). Same persona can appear
 * twice in `chat_personas` with different models — the pivot stays
 * permissive (no unique index on (chat_id, persona_id)).
 *
 * `chats.panel_config` holds the raw input the user gave at creation
 * (`{"agents": 5}`, `{"models": [3, 11]}`, or `{"pairs": [...]}`) so the
 * BoardroomComposer can replay if needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personas', function (Blueprint $table) {
            // Drop FK constraint first so we can null the column. Models
            // assigned in PersonaModelSeeder remain as the *default* for the
            // persona; chat_personas.ai_model_id overrides per-chat.
            $table->dropForeign(['ai_model_id']);
            $table->unsignedBigInteger('ai_model_id')->nullable()->change();
            $table->foreign('ai_model_id')->references('id')->on('ai_models')->nullOnDelete();
        });

        Schema::table('chats', function (Blueprint $table) {
            $table->enum('init_mode', ['quick', 'custom_models', 'custom'])
                ->default('custom')
                ->after('strong');
            $table->json('panel_config')->nullable()->after('init_mode');
            // True once BoardroomComposer ran for a quick/custom_models chat
            // so we never compose twice for the same chat.
            $table->boolean('panel_composed')->default(false)->after('panel_config');
        });

        Schema::table('chat_personas', function (Blueprint $table) {
            // Per-chat model override. null means "use persona's default
            // ai_model_id". PersonaResponder::modelFor() resolves chain.
            $table->foreignId('ai_model_id')->nullable()->after('persona_id')
                ->constrained('ai_models')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chat_personas', function (Blueprint $table) {
            $table->dropForeign(['ai_model_id']);
            $table->dropColumn('ai_model_id');
        });

        Schema::table('chats', function (Blueprint $table) {
            $table->dropColumn(['init_mode', 'panel_config', 'panel_composed']);
        });

        Schema::table('personas', function (Blueprint $table) {
            $table->dropForeign(['ai_model_id']);
            $table->unsignedBigInteger('ai_model_id')->nullable(false)->change();
            $table->foreign('ai_model_id')->references('id')->on('ai_models');
        });
    }
};
