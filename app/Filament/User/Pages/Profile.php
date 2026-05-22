<?php

namespace App\Filament\User\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * User-editable account fields — name, email, country, VAT ID. Changing the
 * email clears `email_verified_at` and bounces the user back to the verify
 * flow so we never bill a half-verified account. VAT ID is captured but its
 * VIES validation lives elsewhere (TopupCodeService / Paddle path) so we
 * don't block the user from saving while waiting on an external API.
 */
class Profile extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static string|\UnitEnum|null $navigationGroup = 'Account';

    protected static ?string $navigationLabel = 'My data';

    protected static ?string $title = 'My data';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.user.pages.profile';

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        // Pre-fill from the model — Filament's `fill()` reads $this->data so
        // we hand it the user's current values in one shot.
        $user = auth()->user();
        $this->form->fill([
            'name' => $user->name,
            'email' => $user->email,
            'country_code' => $user->country_code,
            'vat_id' => $user->vat_id,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                TextInput::make('name')
                    ->label('Full name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->required()
                    ->unique('users', 'email', ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->ignore(auth()->id()))
                    ->helperText('If you change this, we will email you a new verification link.'),
                Select::make('country_code')
                    ->label('Country')
                    ->options($this->countries())
                    ->searchable()
                    ->placeholder('— Not set —'),
                TextInput::make('vat_id')
                    ->label('VAT / OIB (optional)')
                    ->maxLength(32)
                    ->helperText('For B2B invoicing. Leave empty if you are a private user.'),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save changes')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('primary')
                ->action(function () {
                    $state = $this->form->getState();
                    $user = auth()->user();
                    $user->fill($state);

                    // Re-verification gate — same logic Breeze used. Whenever
                    // the email changes, the user goes back to "unverified"
                    // and has to click a fresh link.
                    $emailChanged = $user->isDirty('email');
                    if ($emailChanged) {
                        $user->email_verified_at = null;
                    }

                    $user->save();

                    if ($emailChanged) {
                        $user->sendEmailVerificationNotification();
                        Notification::make()
                            ->title('Verification email sent')
                            ->body('We sent a confirmation link to your new email. Click it to keep using your account.')
                            ->success()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Saved')
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * Subset of ISO 3166-1 alpha-2 codes — Croatia first, then EU members,
     * then the rest of the common ones. Sufficient for a SaaS starting from
     * Croatia; the full ~250-country list would just bloat the select.
     */
    private function countries(): array
    {
        $list = [
            'HR' => 'Croatia',
            'AT' => 'Austria',  'BE' => 'Belgium',  'BG' => 'Bulgaria',  'CY' => 'Cyprus',
            'CZ' => 'Czechia',  'DE' => 'Germany',  'DK' => 'Denmark',   'EE' => 'Estonia',
            'ES' => 'Spain',    'FI' => 'Finland',  'FR' => 'France',    'GR' => 'Greece',
            'HU' => 'Hungary',  'IE' => 'Ireland',  'IT' => 'Italy',     'LT' => 'Lithuania',
            'LU' => 'Luxembourg', 'LV' => 'Latvia', 'MT' => 'Malta',     'NL' => 'Netherlands',
            'PL' => 'Poland',   'PT' => 'Portugal', 'RO' => 'Romania',   'SE' => 'Sweden',
            'SI' => 'Slovenia', 'SK' => 'Slovakia',
            'BA' => 'Bosnia and Herzegovina', 'RS' => 'Serbia', 'ME' => 'Montenegro',
            'MK' => 'North Macedonia', 'AL' => 'Albania', 'XK' => 'Kosovo',
            'CH' => 'Switzerland', 'NO' => 'Norway', 'IS' => 'Iceland',
            'GB' => 'United Kingdom', 'US' => 'United States', 'CA' => 'Canada',
            'AU' => 'Australia', 'NZ' => 'New Zealand',
        ];

        return $list;
    }
}
