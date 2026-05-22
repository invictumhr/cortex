{{-- ApiTokenUsageWidget view --}}
<x-filament-widgets::widget>
    <x-filament::section :heading="$heading">
        @if (empty($rows))
            <div class="text-sm text-gray-500 dark:text-gray-400">
                No API calls in the last 30 days.
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="border-b border-gray-200 dark:border-gray-700 text-left">
                        <tr>
                            <th class="py-2 pr-4 font-medium text-gray-700 dark:text-gray-300">Token</th>
                            <th class="py-2 pr-4 font-medium text-gray-700 dark:text-gray-300 text-right">Calls</th>
                            <th class="py-2 pr-4 font-medium text-gray-700 dark:text-gray-300 text-right">Successful</th>
                            <th class="py-2 pr-4 font-medium text-gray-700 dark:text-gray-300 text-right">Spent</th>
                            <th class="py-2 font-medium text-gray-700 dark:text-gray-300">Last call</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach ($rows as $row)
                            <tr>
                                <td class="py-2 pr-4">
                                    <span class="text-gray-900 dark:text-gray-100">{{ $row['label'] }}</span>
                                    @if ($row['revoked'])
                                        <span class="ml-2 inline-block rounded bg-rose-100 text-rose-700 dark:bg-rose-900 dark:text-rose-300 px-1.5 py-0.5 text-xs">revoked</span>
                                    @endif
                                </td>
                                <td class="py-2 pr-4 text-gray-700 dark:text-gray-300 text-right tabular-nums">{{ number_format($row['call_count']) }}</td>
                                <td class="py-2 pr-4 text-emerald-600 dark:text-emerald-400 text-right tabular-nums">{{ number_format($row['ok_count']) }}</td>
                                <td class="py-2 pr-4 text-sky-600 dark:text-sky-400 text-right tabular-nums">€{{ number_format($row['user_cost'], 4) }}</td>
                                <td class="py-2 text-gray-500 dark:text-gray-400">{{ $row['last_call_at'] ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
