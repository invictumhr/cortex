<?php

namespace Tests\Feature\Chat;

use App\Jobs\GeneratePersonaResponse;
use App\Models\Chat;
use App\Models\ChatMessage;
use App\Models\Persona;
use App\Models\User;
use App\Services\Chat\ChatOrchestrator;
use App\Services\Chat\ContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrchestrationHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Orch User '.uniqid(),
            'email' => 'orch'.uniqid().'@example.test',
            'password' => 'irrelevant',
        ]);
    }

    private function makeChat(User $user, array $attributes = []): Chat
    {
        return Chat::create(array_merge([
            'user_id' => $user->id,
            'title' => 'Hardening test',
            'rounds_per_turn' => 3,
            'scribe_interval' => 50,
            'language' => 'Croatian',
            'status' => Chat::STATUS_ACTIVE,
            'current_round' => 0,
        ], $attributes));
    }

    private function makePersona(): Persona
    {
        return Persona::create([
            'name' => 'Test Persona '.uniqid(),
            'slug' => 'test-persona-'.uniqid(),
            'title' => 'Tester',
            'description' => 'Persona za testove.',
            'system_prompt' => 'Ti si test persona.',
            'is_active' => true,
            'sort_order' => 1,
        ]);
    }

    public function test_start_round_claims_round_atomically_and_skips_duplicates(): void
    {
        Queue::fake();

        $user = $this->makeUser();
        $chat = $this->makeChat($user, ['current_round' => 1]);
        $persona = $this->makePersona();
        $chat->personas()->attach($persona->id, ['is_active' => true, 'joined_at' => now()]);

        $orchestrator = app(ChatOrchestrator::class);

        // Two concurrent flows both try to start round 2 — only the first
        // claim may dispatch a persona chain, or the round gets double-billed.
        $orchestrator->startRound($chat->fresh(), turn: 1, round: 2);
        $orchestrator->startRound($chat->fresh(), turn: 1, round: 2);

        Queue::assertPushed(GeneratePersonaResponse::class, 1);
        $this->assertSame(2, (int) $chat->fresh()->current_round);
    }

    public function test_start_round_allows_consecutive_rounds(): void
    {
        Queue::fake();

        $user = $this->makeUser();
        $chat = $this->makeChat($user, ['current_round' => 0]);
        $persona = $this->makePersona();
        $chat->personas()->attach($persona->id, ['is_active' => true, 'joined_at' => now()]);

        $orchestrator = app(ChatOrchestrator::class);

        $orchestrator->startRound($chat->fresh(), turn: 1, round: 1);
        $orchestrator->startRound($chat->fresh(), turn: 1, round: 2);

        Queue::assertPushed(GeneratePersonaResponse::class, 2);
        $this->assertSame(2, (int) $chat->fresh()->current_round);
    }

    public function test_context_builder_trims_transcript_to_token_budget(): void
    {
        // The char budget floors at 4000, so three 3000-char messages (~9k
        // chars) must shrink to the newest one(s) plus the omission marker.
        config(['cortex.context_token_budget' => 100]);

        $user = $this->makeUser();
        $chat = $this->makeChat($user, ['current_round' => 2]);
        $persona = $this->makePersona();
        $chat->personas()->attach($persona->id, ['is_active' => true, 'joined_at' => now()]);

        foreach (['PRVA', 'DRUGA', 'TRECA'] as $i => $marker) {
            ChatMessage::create([
                'chat_id' => $chat->id,
                'user_id' => $user->id,
                'role' => ChatMessage::ROLE_USER,
                'content' => $marker.' '.str_repeat('x', 3000),
                'round_number' => 0,
                'turn_number' => $i + 1,
            ]);
        }

        [, $messages] = app(ContextBuilder::class)->build($chat->fresh(), $persona);
        $payload = $messages[0]->content;

        $this->assertStringContainsString('TRECA', $payload, 'newest message must survive');
        $this->assertStringNotContainsString('PRVA', $payload, 'oldest message must be trimmed');
        $this->assertStringContainsString('Stariji dio rasprave je izostavljen', $payload);
    }

    public function test_context_builder_keeps_everything_under_budget(): void
    {
        config(['cortex.context_token_budget' => 24000]);

        $user = $this->makeUser();
        $chat = $this->makeChat($user, ['current_round' => 2]);
        $persona = $this->makePersona();
        $chat->personas()->attach($persona->id, ['is_active' => true, 'joined_at' => now()]);

        ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'role' => ChatMessage::ROLE_USER,
            'content' => 'Kratka poruka.',
            'round_number' => 0,
            'turn_number' => 1,
        ]);

        [, $messages] = app(ContextBuilder::class)->build($chat->fresh(), $persona);

        $this->assertStringContainsString('Kratka poruka.', $messages[0]->content);
        $this->assertStringNotContainsString('Stariji dio rasprave je izostavljen', $messages[0]->content);
    }

    public function test_pinned_context_and_constraints_live_in_system_prompt(): void
    {
        $user = $this->makeUser();
        $chat = $this->makeChat($user, [
            'context' => 'Laravel 12 aplikacija s MySQL bazom.',
            'constraints' => 'Bez vanjskih SaaS servisa.',
        ]);
        $persona = $this->makePersona();
        $chat->personas()->attach($persona->id, ['is_active' => true, 'joined_at' => now()]);

        [$system, $messages] = app(ContextBuilder::class)->build($chat->fresh(), $persona);

        // Pinned payload belongs to the round-invariant system block (prompt
        // caching prefix); the volatile user message must not duplicate it.
        $this->assertStringContainsString('Laravel 12 aplikacija', $system);
        $this->assertStringContainsString('Bez vanjskih SaaS servisa.', $system);
        $this->assertStringNotContainsString('Laravel 12 aplikacija', $messages[0]->content);
    }
}
