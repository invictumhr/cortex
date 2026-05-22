<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * API access token for the public REST API. Stored as a sha256 hash; the
 * plaintext is shown to the user exactly once at creation time.
 */
class ApiToken extends Model
{
    /**
     * Canonical scope strings exposed by the REST API. Keep these in sync with
     * the CheckboxList in ApiTokenResource so the UI mirrors what middleware
     * actually checks for. Empty `scopes` on a token = all scopes allowed.
     */
    public const SCOPE_DISCUSS = 'cortex:discuss';
    public const SCOPE_CHATS_READ = 'cortex:chats.read';
    public const SCOPE_CHATS_WRITE = 'cortex:chats.write';
    public const SCOPE_WALLET_READ = 'cortex:wallet.read';
    public const SCOPE_KNOWLEDGE = 'cortex:knowledge';

    /** @return array<string, string> Scope → human label for UI checkboxes. */
    public static function availableScopes(): array
    {
        return [
            self::SCOPE_DISCUSS       => 'Start boardroom discussions',
            self::SCOPE_CHATS_READ    => 'List & read existing chats',
            self::SCOPE_CHATS_WRITE   => 'Send follow-up messages, archive, delete chats',
            self::SCOPE_WALLET_READ   => 'Read wallet balance & spend',
            self::SCOPE_KNOWLEDGE     => 'Query the global knowledge base',
        ];
    }

    protected $fillable = [
        'user_id', 'token_hash', 'label', 'scopes', 'last_used_at', 'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function usages(): HasMany
    {
        return $this->hasMany(ApiTokenUsage::class);
    }

    public function chats(): HasMany
    {
        return $this->hasMany(Chat::class, 'initiated_by_token_id');
    }

    /**
     * Generate a new token. Returns [model, plaintext] — only this call ever
     * sees the plaintext, so capture it immediately and surface it to the user.
     *
     * @param  array<string>|null  $scopes
     * @return array{0: self, 1: string}
     */
    public static function issue(User $user, string $label, ?array $scopes = null): array
    {
        // Prefix lets server-side debugging spot a token in logs without
        // exposing the secret. Format mirrors GitHub's `ghp_…` convention.
        $plaintext = 'ctx_'.Str::random(48);

        $token = self::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plaintext),
            'label' => $label,
            'scopes' => $scopes,
        ]);

        return [$token, $plaintext];
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** A token without explicit scopes can hit every endpoint. */
    public function hasScope(string $scope): bool
    {
        if (empty($this->scopes)) {
            return true;
        }

        return in_array($scope, $this->scopes, true);
    }
}
