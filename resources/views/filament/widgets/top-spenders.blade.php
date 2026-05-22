{{-- TopSpendersWidget view --}}
<x-filament-widgets::widget>
    <x-filament::section :heading="$heading">
        @if (empty($rows))
            <div class="text-sm text-gray-500 dark:text-gray-400">
                No billable activity in the last 30 days.
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-200 dark:border-gray-700 text-left">
                        <tr>
                            <th class="py-2 pr-4 font-medium text-gray-700 dark:text-gray-300">User</th>
                            <th class="py-2 pr-4 font-medium text-gray-700 dark:text-gray-300 text-right">Debits</th>
                            <th class="py-2 pr-4 font-medium text-gray-700 dark:text-gray-300 text-right">Our cost</th>
                            <th class="py-2 pr-4 font-medium text-gray-700 dark:text-gray-300 text-right">Billed</th>
                            <th class="py-2 font-medium text-gray-700 dark:text-gray-300 text-right">Margin</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($rows as $row)
                            <tr>
                                <td class="py-2 pr-4">
                                    <div class="text-gray-900 dark:text-gray-100">{{ $row['name'] }}</div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $row['email'] }}</div>
                                </td>
                                <td class="py-2 pr-4 text-gray-700 dark:text-gray-300 text-right tabular-nums">{{ number_format($row['debit_count']) }}</td>
                                <td class="py-2 pr-4 text-amber-600 dark:text-amber-400 text-right tabular-nums">€{{ number_format($row['provider_cost'], 4) }}</td>
                                <td class="py-2 pr-4 text-sky-600 dark:text-sky-400 text-right tabular-nums">€{{ number_format($row['user_cost'], 4) }}</td>
                                <td class="py-2 text-right tabular-nums {{ $row['margin'] >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400' }}">€{{ number_format($row['margin'], 4) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
