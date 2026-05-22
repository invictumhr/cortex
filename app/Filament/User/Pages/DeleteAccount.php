<?php

namespace App\Filament\User\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * GDPR right-to-be-forgotten flow. The user types their password and the
 * literal word "DELETE" — two-factor confirmation that survives a misclick.
 * On submit we log them out, delete the user row, and let MySQL's
 * cascade-on-delete reach the chats, wallet, tokens, etc.
 *
 * Wallet remainder is NOT auto-refunded — top-up codes are anonymous PINs
 * with no payment trail to reverse. If a user has unspent paid balance and
 * asks for a refund, that's a manual ops process (admin grant to a new
 * code → DM the code).
 */
class DeleteAccount extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrash;

    protected static string|\UnitEnum|null $navigationGroup = 'Account';

    protected static ?string $navigationLabel = 'Delete account';

    protected static ?string $title = 'Delete account';

    protected static ?int $navigationSort = 99;

    protected string $view = 'filament.user.pages.delete-account';

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                TextInput::make('password')
                    ->label('Your current password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->autocomplete('current-password'),
                TextInput::make('confirmation')
                    ->label('Type DELETE to confirm')
                    ->required()
                    ->placeholder('DELETE')
                    ->extraInputAttributes(['autocomplete' => 'off', 'spellcheck' => 'false']),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('delete')
                ->label('Delete account permanently')
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('This cannot be undone.')
                ->modalDescription('Your chats, wallet, API tokens and ledger will be wiped. Anonymised durable insights stay in our knowledge base. Continue?')
                ->modalSubmitActionLabel('Yes, delete forever')
                ->action(function () {
                    $state = $this->form->getState();
                    $user = auth()->user();

                    if (! Hash::check($state['password'] ?? '', $user->password)) {
                        throw ValidationException::withMessages([
                            'data.password' => 'Wrong password.',
                        ]);
                    }
                    if (strtoupper(trim((string) ($state['confirmation'] ?? ''))) !== 'DELETE') {
                        throw ValidationException::withMessages([
                            'data.confirmation' => 'Type the word DELETE exactly.',
                        ]);
                    }

                    Auth::logout();

                    // Cascades hit chats, wallet, wallet_transactions,
                    // api_tokens, api_token_usages via FK ON DELETE CASCADE.
                    $user->delete();

                    request()->session()->invalidate();
                    request()->session()->regenerateToken();

                    return redirect('/');
                }),
        ];
    }
}
