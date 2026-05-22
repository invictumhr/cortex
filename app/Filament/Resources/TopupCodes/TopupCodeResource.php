<?php

namespace App\Filament\Resources\TopupCodes;

use App\Filament\Resources\TopupCodes\Pages\ListTopupCodes;
use App\Models\TopupCode;
use App\Services\Billing\TopupCodeService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Admin TopupCode management. Provides:
 *  - the global list of issued codes (status, batch, redeemed-by, value)
 *  - the "Generate batch" header action, which surfaces the plaintext PINs
 *    in a persistent notification — the ONLY time admin sees them.
 */
class TopupCodeResource extends Resource
{
    protected static ?string $model = TopupCode::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTicket;

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?string $modelLabel = 'Top-up code';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable()->toggleable(),
                TextColumn::make('batch_label')
                    ->placeholder('—')
                    ->badge()
                    ->color('gray')
                    ->searchable(),
                TextColumn::make('amount')->money('EUR')->sortable(),
                TextColumn::make('status')
                    ->state(fn (TopupCode $r) => $r->isRedeemed() ? 'redeemed' : 'open')
                    ->badge()
                    ->color(fn ($state) => $state === 'redeemed' ? 'success' : 'warning'),
                TextColumn::make('redeemedBy.email')
                    ->placeholder('—')
                    ->label('Redeemed by'),
                TextColumn::make('redeemed_at')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(['open' => 'Open', 'redeemed' => 'Redeemed'])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        'open' => $query->whereNull('redeemed_at'),
                        'redeemed' => $query->whereNotNull('redeemed_at'),
                        default => $query,
                    }),
                SelectFilter::make('batch_label')
                    ->options(fn () => TopupCode::query()
                        ->whereNotNull('batch_label')
                        ->distinct()
                        ->pluck('batch_label', 'batch_label')
                        ->all()),
            ])
            ->headerActions([
                Action::make('generate_batch')
                    ->label('Generate batch')
                    ->icon(Heroicon::OutlinedSparkles)
                    ->color('primary')
                    ->modalDescription('Generate N new codes worth the same amount. Plaintext is shown ONCE — copy or paste it to SMS / spreadsheet immediately.')
                    ->schema([
                        TextInput::make('count')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->maxValue(1000)
                            ->default(10)
                            ->helperText('How many codes to mint'),
                        TextInput::make('amount')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->step(0.01)
                            ->default(5)
                            ->prefix('€')
                            ->helperText('EUR value of each code'),
                        TextInput::make('batch_label')
                            ->maxLength(64)
                            ->placeholder('SMS Q2 2026')
                            ->helperText('Optional grouping label'),
                    ])
                    ->action(function (array $data) {
                        $service = app(TopupCodeService::class);
                        $issued = $service->generateBatch(
                            count: (int) $data['count'],
                            eachAmount: (float) $data['amount'],
                            batchLabel: $data['batch_label'] ?: null,
                            createdBy: auth()->user(),
                        );

                        // Persistent notification carries the PIN list — admin
                        // copies them out before clicking away.
                        $lines = array_map(
                            fn ($row) => '#'.$row['model']->id.'  '.TopupCodeService::formatForDisplay($row['plaintext']),
                            $issued,
                        );
                        Notification::make()
                            ->title(count($issued).' codes generated — copy now')
                            ->body("These plaintext PINs are shown ONCE:\n\n```\n".implode("\n", $lines)."\n```")
                            ->success()
                            ->persistent()
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTopupCodes::route('/'),
        ];
    }

    /** Codes are only created via the batch action; no per-record create. */
    public static function canCreate(): bool
    {
        return false;
    }
}
