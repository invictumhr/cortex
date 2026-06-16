<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    protected static function booted(): void
    {
        // GDPR completeness: FK cascades wipe the user's DB rows but fire no
        // Eloquent events, so attachment FILES would stay on disk forever.
        // Purge each chat's upload directory while the rows still exist.
        // Storage failure must never block an account deletion — log and go.
        static::deleting(function (User $user) {
            $disk = \Illuminate\Support\Facades\Storage::disk(config('filesystems.default', 'local'));

            foreach ($user->chats()->pluck('id') as $chatId) {
                try {
                    $disk->deleteDirectory('chat-attachments/'.$chatId);
                } catch (\Throwable $e) {
                    report($e);
                }
            }
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'is_admin',
        'vat_id',
        'vat_validation_status',
        'country_code',
        'free_credit_granted_at',
        'registration_ip_hash',
        'registration_ua_hash',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'registration_ip_hash',
        'registration_ua_hash',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'free_credit_granted_at' => 'datetime',
        ];
    }

    public function chats(): HasMany
    {
        return $this->hasMany(Chat::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function apiTokens(): HasMany
    {
        return $this->hasMany(ApiToken::class);
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    /**
     * Filament panel access — admin panel only for is_admin users; user panel
     * stays open to everyone else who has verified their email. Both panels
     * share the `web` guard, so we gate them here by panel ID rather than
     * with separate auth guards.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        // The verified-email gate lifts after the user clicks the link in
        // their inbox. Admins are exempt — they're seeded internally and
        // never go through the public signup flow.
        $verified = $this->email_verified_at !== null;

        return match ($panel->getId()) {
            'admin' => $this->isAdmin(),
            // Both admins and regular users get the customer-facing /user panel
            // (wallet, profile, API tokens). Verified-email gate still applies
            // — admins are seeded with email_verified_at filled in, so they
            // sail through.
            'user' => $verified,
            default => $verified,
        };
    }
}
