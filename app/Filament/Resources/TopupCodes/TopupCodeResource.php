<?php

namespace App\Filament\Resources\TopupCodes;

use App\Filament\Resources\TopupCodes\Pages\ListTopupCodes;
use App\Models\TopupCode;
use App\Models\TopupCodeBatch;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Admin per-code list. Read-only: the actual minting flow lives in the sibling
 * "Code generation jobs" resource (TopupCodeBatchResource). This page exists so
 * admin can audit individual codes (who redeemed, when, what amount, which
 * batch they belong to).
 *
 * The batch_id filter is the deep-link target from the batch resource's
 * "View codes" row action, so visiting this page already filtered to one batch
 * is the common navigation path.
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
                TextColumn::make('batch.label')
                    ->label('Batch')
                    ->placeholder('—')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->description(fn (TopupCode $r) => $r->batch_id ? '#'.$r->batch_id : null),
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
                SelectFilter::make('batch_id')
                    ->label('Batch')
                    ->options(fn () => TopupCodeBatch::query()
                        ->orderByDesc('id')
                        ->pluck('label', 'id')
                        ->all())
                    ->searchable(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTopupCodes::route('/'),
        ];
    }

    /** Codes are only created via the batch resource; no per-record create. */
    public static function canCreate(): bool
    {
        return false;
    }
}
