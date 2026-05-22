<?php

namespace App\Filament\User\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Self-service password change. Mirrors Breeze's UpdatePasswordForm —
 * requires the current password, enforces Laravel's default Password rules
 * (length, mixed case, etc.) on the new one, and confirms it via a paired
 * input. The current-session cookie stays valid; we don't yank the user
 * out from under themselves on a successful change.
 */
class ChangePassword extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static string|\UnitEnum|null $navigationGroup = 'Account';

    protected static ?string $navigationLabel = 'Password';

    protected static ?string $title = 'Change password';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.user.pages.change-password';

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
                TextInput::make('current_password')
                    ->label('Current password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->autocomplete('current-password'),
                TextInput::make('password')
                    ->label('New password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->rule(Password::defaults())
                    ->autocomplete('new-password')
                    ->helperText('At least 8 characters, mixed case and a number.'),
                TextInput::make('password_confirmation')
                    ->label('Confirm new password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->same('password')
                    ->autocomplete('new-password'),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Update password')
                ->icon(Heroicon::OutlinedKey)
                ->color('primary')
                ->action(function () {
                    $state = $this->form->getState();
                    $user = auth()->user();

                    // Manual check — Filament's form doesn't run the
                    // current_password validator out of the box for an
                    // arbitrary user model, and we want the message tied to
                    // the field that's wrong.
                    if (! Hash::check($state['current_password'] ?? '', $user->password)) {
                        throw ValidationException::withMessages([
                            'data.current_password' => 'Current password does not match.',
                        ]);
                    }

                    $user->update(['password' => $state['password']]);

                    $this->form->fill();

                    Notification::make()
                        ->title('Password updated')
                        ->body('Use the new password next time you sign in.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
