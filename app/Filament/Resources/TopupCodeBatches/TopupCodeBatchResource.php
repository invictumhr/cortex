<?php

namespace App\Filament\Resources\TopupCodeBatches;

use App\Filament\Resources\TopupCodeBatches\Pages\ListTopupCodeBatches;
use App\Filament\Resources\TopupCodes\TopupCodeResource;
use App\Models\TopupCodeBatch;
use App\Services\Billing\TopupCodeService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Code Generation Jobs — admin-facing list of batches. Each row is one
 * generation run that minted N codes at the same face value; clicking
 * through links to the code list filtered to that batch, and the "Download
 * CSV" action streams the plaintext PINs (one per line) for SMS dispatch.
 *
 * Batch creation moved here from the per-code resource: admin always thinks
 * in terms of "I need to mint 100 codes for the May SMS push", not "I'll
 * mint individual codes one by one".
 */
class TopupCodeBatchResource extends Resource
{
    protected static ?string $model = TopupCodeBatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?string $navigationLabel = 'Code generation jobs';

    protected static ?string $modelLabel = 'Code generation job';

    protected static ?string $pluralModelLabel = 'Code generation jobs';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('label')
                    ->searchable()
                    ->weight('medium'),
                TextColumn::make('code_count')
                    ->label('Codes')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('amount_per_code')
                    ->label('Per code')
                    ->money('EUR')
                    ->sortable(),
                TextColumn::make('total_value')
                    ->label('Total value')
                    ->state(fn (TopupCodeBatch $r) => $r->totalValue())
                    ->money('EUR'),
                TextColumn::make('redeemed_count')
                    ->label('Redeemed')
                    ->state(fn (TopupCodeBatch $r) => $r->codes()->whereNotNull('redeemed_at')->count().' / '.$r->code_count),
                TextColumn::make('createdBy.email')
                    ->label('Issued by')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->defaultSort('id', 'desc')
            ->headerActions([
                Action::make('new_batch')
                    ->label('New batch')
                    ->icon(Heroicon::OutlinedSparkles)
                    ->color('primary')
                    ->modalHeading('Generate a batch of top-up codes')
                    ->modalDescription('Mints N codes worth the same amount and links them to a new batch. Plaintext is shown ONCE here, but you can re-download the CSV from the batch row at any time.')
                    ->schema([
                        TextInput::make('label')
                            ->required()
                            ->maxLength(120)
                            ->placeholder('SMS Q2 2026')
                            ->helperText('Human label — shown in the list and the CSV filename.'),
                        TextInput::make('count')
                            ->numeric()
                            ->required()
                            ->minValue(1)
                            ->maxValue(10000)
                            ->default(10)
                            ->helperText('How many codes to mint (1–10 000).'),
                        TextInput::make('amount')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->step(0.01)
                            ->default(5)
                            ->prefix('€')
                            ->helperText('EUR value per code. All codes in a batch share this amount.'),
                        Textarea::make('notes')
                            ->maxLength(2000)
                            ->rows(3)
                            ->placeholder('Distribution channel, SMS provider job id, owner — anything future-you will want to know.'),
                    ])
                    ->action(function (array $data) {
                        $result = app(TopupCodeService::class)->generateBatch(
                            count: (int) $data['count'],
                            eachAmount: (float) $data['amount'],
                            batchLabel: $data['label'],
                            createdBy: auth()->user(),
                            notes: $data['notes'] ?: null,
                        );

                        $batch = $result['batch'];
                        $codes = $result['codes'];
                        $downloadUrl = route('admin.topup-batches.export', $batch);

                        // Persistent notification surfaces the just-minted PINs
                        // so admin can copy them straight away. The CSV link
                        // remains usable later from the batch row.
                        $lines = array_map(
                            fn ($row) => '#'.$row['model']->id.'  '.TopupCodeService::formatForDisplay($row['plaintext']),
                            $codes,
                        );
                        Notification::make()
                            ->title('Batch #'.$batch->id.' — '.count($codes).' codes minted')
                            ->body("Plaintext PINs:\n\n```\n".implode("\n", $lines)."\n```\n\nDownload as CSV: $downloadUrl")
                            ->success()
                            ->persistent()
                            ->send();
                    }),
            ])
            ->recordActions([
                Action::make('download_csv')
                    ->label('Download CSV')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('primary')
                    ->url(fn (TopupCodeBatch $record) => route('admin.topup-batches.export', $record))
                    ->openUrlInNewTab(),
                Action::make('view_codes')
                    ->label('View codes')
                    ->icon(Heroicon::OutlinedListBullet)
                    ->color('gray')
                    // `batch` is read in ListTopupCodes::mount() and seeded
                    // into the batch_id filter state — kept as a simple
                    // named parameter instead of Filament's tableFilters[..]
                    // serialisation, which isn't enabled by default in v5.
                    ->url(fn (TopupCodeBatch $record) => TopupCodeResource::getUrl('index', [
                        'batch' => $record->id,
                    ])),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTopupCodeBatches::route('/'),
        ];
    }

    /** Batches are only created via the header action; no per-record create page. */
    public static function canCreate(): bool
    {
        return false;
    }
}
