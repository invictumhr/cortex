<?php

namespace App\Filament\User\Resources\ApiTokens\Pages;

use App\Filament\User\Resources\ApiTokens\ApiTokenResource;
use App\Models\ApiToken;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateApiToken extends CreateRecord
{
    protected static string $resource = ApiTokenResource::class;

    /**
     * Bypass Filament's auto-fill and call ApiToken::issue() so the plaintext
     * is generated once and surfaced to the user via a notification. The
     * record persisted is the hashed shell.
     */
    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        $scopes = $data['scopes'] ?? null;
        if (is_array($scopes) && empty($scopes)) {
            $scopes = null; // empty array → all scopes (per ApiToken::hasScope)
        }

        [$token, $plaintext] = ApiToken::issue(
            user: auth()->user(),
            label: $data['label'],
            scopes: $scopes,
        );

        // Notification persists across the redirect — only chance the user
        // has to copy the secret. Stored once, never recoverable.
        Notification::make()
            ->title('API token created')
            ->body("Copy your token now — it won't be shown again:\n\n`{$plaintext}`")
            ->success()
            ->persistent()
            ->send();

        return $token;
    }
}
