<?php

namespace App\Filament\User\Pages;

use App\Services\Billing\TopupCodeService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * User self-service redemption page. The user types a 14-digit top-up PIN
 * delivered to them over SMS (or paper voucher), the backend hashes it,
 * looks up the matching code, and credits their wallet via WalletService.
 *
 * Rate-limited per-IP and per-user inside TopupCodeService to make
 * brute-forcing the 14-digit space impractical.
 */
class RedeemCode extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?string $navigationLabel = 'Top up (PIN)';

    protected static ?string $title = 'Redeem a top-up code';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.user.pages.redeem-code';

    public ?string $code = null;

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                TextInput::make('code')
                    ->label('Top-up PIN')
                    ->placeholder('1234-5678-9012-34')
                    ->required()
                    ->autocomplete('off')
                    ->extraInputAttributes(['inputmode' => 'numeric', 'autocomplete' => 'off'])
                    ->helperText('14 digits, dashes optional. Sent to you via SMS after purchase.'),
            ]);
    }

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('redeem')
                ->label('Redeem')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('primary')
                ->action(function () {
                    $state = $this->form->getState();
                    $service = app(TopupCodeService::class);

                    try {
                        $code = $service->redeem(
                            user: auth()->user(),
                            rawCode: (string) ($state['code'] ?? ''),
                            request: request(),
                        );
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Redemption failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Code redeemed — €'.number_format((float) $code->amount, 2).' added')
                        ->body('Your new spendable balance is shown on the dashboard.')
                        ->success()
                        ->send();

                    $this->form->fill();
                }),
        ];
    }
}
