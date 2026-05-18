<?php

namespace App\Filament\Widgets;

use App\Models\Chat;
use App\Models\Persona;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class CortexCostStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $totalCost = (float) Chat::sum('total_cost');
        $monthCost = (float) Chat::where('created_at', '>=', now()->startOfMonth())->sum('total_cost');
        $messages = (int) Chat::sum('total_messages');
        $inputTokens = (int) Chat::sum('total_input_tokens');
        $outputTokens = (int) Chat::sum('total_output_tokens');

        $topPersona = Persona::query()
            ->withCount('messages')
            ->orderByDesc('messages_count')
            ->first();

        return [
            Stat::make('Ukupni trošak', '€'.number_format($totalCost, 4))
                ->description('Ovaj mjesec: €'.number_format($monthCost, 4))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('success'),

            Stat::make('Chatova', (string) Chat::count())
                ->description(number_format($messages).' poruka ukupno')
                ->descriptionIcon('heroicon-m-chat-bubble-left-right')
                ->color('primary'),

            Stat::make('Tokena', number_format($inputTokens + $outputTokens))
                ->description(number_format($inputTokens).' ulaz / '.number_format($outputTokens).' izlaz')
                ->descriptionIcon('heroicon-m-cpu-chip')
                ->color('info'),

            Stat::make('Najaktivnija persona', $topPersona?->name ?? '—')
                ->description(number_format((int) ($topPersona?->messages_count ?? 0)).' poruka')
                ->descriptionIcon('heroicon-m-trophy')
                ->color('warning'),
        ];
    }
}
