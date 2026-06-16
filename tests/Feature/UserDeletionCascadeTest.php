<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Chat;
use App\Models\ChatAttachment;
use App\Models\ChatMessage;
use App\Models\User;
use App\Services\Billing\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * GDPR completeness: deleting a user must wipe every DB row AND every
 * uploaded file. The rows go via FK cascade; the files go via the
 * User::deleting model hook (cascades fire no Eloquent events).
 */
class UserDeletionCascadeTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_user_cascades_rows_and_purges_attachment_files(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);

        $user = User::create([
            'name' => 'Doomed User',
            'email' => 'doomed'.uniqid().'@example.test',
            'password' => 'irrelevant',
        ]);

        $wallet = app(WalletService::class)->forUser($user);
        app(WalletService::class)->grant($wallet, 5.0, 'seed');

        [$token] = ApiToken::issue($user, 'doomed token');

        $chat = Chat::create([
            'user_id' => $user->id,
            'title' => 'Doomed chat',
            'rounds_per_turn' => 2,
            'scribe_interval' => 50,
            'language' => 'Croatian',
            'status' => Chat::STATUS_PAUSED,
        ]);

        $message = ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $user->id,
            'role' => ChatMessage::ROLE_USER,
            'content' => 'Tajna poruka',
            'round_number' => 0,
            'turn_number' => 1,
        ]);

        $filePath = 'chat-attachments/'.$chat->id.'/slika.png';
        Storage::disk('local')->put($filePath, 'fake-image-bytes');
        $message->attachments()->create([
            'type' => ChatAttachment::TYPE_IMAGE,
            'file_path' => $filePath,
            'mime_type' => 'image/png',
            'file_size' => 16,
        ]);

        $this->assertTrue(Storage::disk('local')->exists($filePath));

        $user->delete();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseMissing('chats', ['id' => $chat->id]);
        $this->assertDatabaseMissing('chat_messages', ['id' => $message->id]);
        $this->assertDatabaseMissing('wallets', ['id' => $wallet->id]);
        $this->assertDatabaseMissing('wallet_transactions', ['wallet_id' => $wallet->id]);
        $this->assertDatabaseMissing('api_tokens', ['id' => $token->id]);
        $this->assertFalse(
            Storage::disk('local')->exists($filePath),
            'attachment files must be purged from disk on account deletion',
        );
    }

    public function test_deleting_single_chat_purges_its_files(): void
    {
        Storage::fake('local');
        config(['filesystems.default' => 'local']);

        $user = User::create([
            'name' => 'Chat Owner',
            'email' => 'owner'.uniqid().'@example.test',
            'password' => 'irrelevant',
        ]);

        $chat = Chat::create([
            'user_id' => $user->id,
            'title' => 'Chat with file',
            'rounds_per_turn' => 2,
            'scribe_interval' => 50,
            'language' => 'Croatian',
            'status' => Chat::STATUS_PAUSED,
        ]);

        $filePath = 'chat-attachments/'.$chat->id.'/dokument.png';
        Storage::disk('local')->put($filePath, 'bytes');

        $chat->delete();

        $this->assertFalse(Storage::disk('local')->exists($filePath));
    }
}
