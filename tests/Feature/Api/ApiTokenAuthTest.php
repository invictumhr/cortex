<?php

namespace Tests\Feature\Api;

use App\Models\ApiToken;
use App\Models\Chat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTokenAuthTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Api User '.uniqid(),
            'email' => 'api'.uniqid().'@example.test',
            'password' => 'irrelevant',
        ]);
    }

    /** @return array{0: ApiToken, 1: string} */
    private function makeToken(User $user, ?array $scopes = null): array
    {
        return ApiToken::issue($user, 'test token', $scopes);
    }

    public function test_missing_bearer_returns_401(): void
    {
        $this->getJson('/api/v1/chats')
            ->assertStatus(401)
            ->assertJson(['error' => 'missing_bearer_token']);
    }

    public function test_invalid_token_returns_401(): void
    {
        $this->getJson('/api/v1/chats', ['Authorization' => 'Bearer ctx_definitely_not_real'])
            ->assertStatus(401)
            ->assertJson(['error' => 'invalid_token']);
    }

    public function test_revoked_token_returns_401(): void
    {
        [$token, $plain] = $this->makeToken($this->makeUser());
        $token->update(['revoked_at' => now()]);

        $this->getJson('/api/v1/chats', ['Authorization' => 'Bearer '.$plain])
            ->assertStatus(401)
            ->assertJson(['error' => 'token_revoked']);
    }

    public function test_token_without_required_scope_returns_403(): void
    {
        [, $plain] = $this->makeToken($this->makeUser(), [ApiToken::SCOPE_WALLET_READ]);

        $this->getJson('/api/v1/chats', ['Authorization' => 'Bearer '.$plain])
            ->assertStatus(403)
            ->assertJson(['error' => 'scope_required', 'required' => ApiToken::SCOPE_CHATS_READ]);
    }

    public function test_null_scope_token_passes_all_endpoints(): void
    {
        [, $plain] = $this->makeToken($this->makeUser(), null);

        $this->getJson('/api/v1/chats', ['Authorization' => 'Bearer '.$plain])
            ->assertOk();
    }

    public function test_cross_user_chat_access_is_forbidden(): void
    {
        $owner = $this->makeUser();
        $attackerToken = $this->makeToken($this->makeUser())[1];

        $chat = Chat::create([
            'user_id' => $owner->id,
            'title' => 'Private chat',
            'rounds_per_turn' => 2,
            'scribe_interval' => 50,
            'language' => 'Croatian',
            'status' => Chat::STATUS_PAUSED,
        ]);

        $this->getJson('/api/v1/chats/'.$chat->public_id, ['Authorization' => 'Bearer '.$attackerToken])
            ->assertStatus(403)
            ->assertJson(['error' => 'forbidden']);

        $this->deleteJson('/api/v1/chats/'.$chat->public_id, [], ['Authorization' => 'Bearer '.$attackerToken])
            ->assertStatus(403);

        $this->assertDatabaseHas('chats', ['id' => $chat->id]);
    }

    public function test_rate_limit_returns_429_with_retry_after(): void
    {
        config(['cortex.api_rate_limit.per_minute' => 2, 'cortex.api_rate_limit.per_hour' => 100]);
        [, $plain] = $this->makeToken($this->makeUser());

        $headers = ['Authorization' => 'Bearer '.$plain];
        $this->getJson('/api/v1/chats', $headers)->assertOk();
        $this->getJson('/api/v1/chats', $headers)->assertOk();

        $this->getJson('/api/v1/chats', $headers)
            ->assertStatus(429)
            ->assertJson(['error' => 'rate_limited', 'window' => 'minute'])
            ->assertHeader('Retry-After');
    }

    public function test_personas_and_models_endpoints_require_only_a_valid_token(): void
    {
        // Scoped to wallet.read only — roster discovery must still work.
        [, $plain] = $this->makeToken($this->makeUser(), [ApiToken::SCOPE_WALLET_READ]);
        $headers = ['Authorization' => 'Bearer '.$plain];

        $personas = $this->getJson('/api/v1/personas', $headers)->assertOk()->json('personas');
        $this->assertNotEmpty($personas, 'seeded roster must be visible');
        $this->assertArrayHasKey('slug', $personas[0]);
        $this->assertArrayHasKey('role', $personas[0]);

        $this->getJson('/api/v1/models', $headers)->assertOk()->assertJsonStructure(['models']);

        $this->getJson('/api/v1/personas')->assertStatus(401);
    }

    public function test_chats_index_reports_has_more_pagination(): void
    {
        $user = $this->makeUser();
        [, $plain] = $this->makeToken($user);

        foreach (range(1, 3) as $i) {
            Chat::create([
                'user_id' => $user->id,
                'title' => "Chat $i",
                'rounds_per_turn' => 2,
                'scribe_interval' => 50,
                'language' => 'Croatian',
                'status' => Chat::STATUS_PAUSED,
            ]);
        }

        $page = $this->getJson('/api/v1/chats?limit=2', ['Authorization' => 'Bearer '.$plain])
            ->assertOk()
            ->json();

        $this->assertCount(2, $page['chats']);
        $this->assertTrue($page['has_more']);
        $this->assertNotNull($page['next_before']);

        $lastPage = $this->getJson('/api/v1/chats?limit=50', ['Authorization' => 'Bearer '.$plain])->json();
        $this->assertFalse($lastPage['has_more']);
        $this->assertNull($lastPage['next_before']);
    }

    public function test_validation_error_is_logged_to_token_usage(): void
    {
        $user = $this->makeUser();
        [$token, $plain] = $this->makeToken($user);

        $this->getJson('/api/v1/chats?limit=9999', ['Authorization' => 'Bearer '.$plain])
            ->assertStatus(422);

        $this->assertDatabaseHas('api_token_usages', [
            'api_token_id' => $token->id,
            'endpoint' => 'GET /api/v1/chats',
            'status' => 'error',
        ]);
    }

    public function test_idempotency_key_replays_instead_of_double_billing(): void
    {
        \Illuminate\Support\Facades\Queue::fake();

        $user = $this->makeUser();
        [, $plain] = $this->makeToken($user);
        app(\App\Services\Billing\WalletService::class)
            ->grant(app(\App\Services\Billing\WalletService::class)->forUser($user), 5.0, 'seed');

        $chat = Chat::create([
            'user_id' => $user->id,
            'title' => 'Idempotent chat',
            'rounds_per_turn' => 2,
            'scribe_interval' => 50,
            'language' => 'Croatian',
            'continuous' => false,
            'panel_composed' => true, // skip BoardroomComposer (would call AI)
            'status' => Chat::STATUS_PAUSED,
        ]);
        $persona = \App\Models\Persona::create([
            'name' => 'Idem Persona', 'slug' => 'idem-'.uniqid(), 'title' => 'T',
            'description' => 'd', 'system_prompt' => 's', 'is_active' => true, 'sort_order' => 1,
        ]);
        $chat->personas()->attach($persona->id, ['is_active' => true, 'joined_at' => now()]);

        $headers = ['Authorization' => 'Bearer '.$plain, 'Idempotency-Key' => 'retry-safe-123'];

        $first = $this->postJson('/api/v1/chats/'.$chat->public_id.'/messages', [
            'content' => 'Prva poruka',
        ], $headers)->assertStatus(202)->json();

        // Chat goes active after the first send — flip it back so the replay
        // path (not the 409 guard) is what we exercise. refresh() first: the
        // in-memory instance still says "paused", so update() would no-op.
        $chat->refresh();
        $chat->update(['status' => Chat::STATUS_PAUSED]);

        $second = $this->postJson('/api/v1/chats/'.$chat->public_id.'/messages', [
            'content' => 'Prva poruka',
        ], $headers)
            ->assertStatus(202)
            ->assertHeader('Idempotency-Replayed', 'true')
            ->json();

        $this->assertSame($first['user_message_id'], $second['user_message_id']);
        $this->assertSame(1, $chat->messages()->where('role', 'user')->count(), 'retry must not create a second user message');
    }

    public function test_message_to_running_bounded_chat_returns_409(): void
    {
        $user = $this->makeUser();
        [, $plain] = $this->makeToken($user);

        $chat = Chat::create([
            'user_id' => $user->id,
            'title' => 'Running chat',
            'rounds_per_turn' => 2,
            'scribe_interval' => 50,
            'language' => 'Croatian',
            'continuous' => false,
            'status' => Chat::STATUS_ACTIVE, // turn mid-flight
        ]);

        $this->postJson('/api/v1/chats/'.$chat->public_id.'/messages', [
            'content' => 'Follow-up while running',
        ], ['Authorization' => 'Bearer '.$plain])
            ->assertStatus(409)
            ->assertJson(['error' => 'discussion_running']);
    }
}
