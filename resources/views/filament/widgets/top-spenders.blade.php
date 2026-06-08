{{-- TopSpendersWidget view --}}
<x-filament-widgets::widget>
    <x-filament::section :heading="$heading">
        @if (empty($rows))
            <div class="text-sm text-gray-500 dark:text-gray-400">
                No billable activity in the last 30 days.
            </div>
        @else
            <div class="overflow-x-auto -mx-2">
                <table style="width:100%;border-collapse:collapse;font-size:0.875rem">
                    <thead>
                        <tr style="border-bottom:1px solid rgb(229 231 235)">
                            <th style="padding:8px 16px 8px 8px;text-align:left;font-weight:500;color:rgb(55 65 81);white-space:nowrap">User</th>
                            <th style="padding:8px 16px 8px 8px;text-align:right;font-weight:500;color:rgb(55 65 81);white-space:nowrap">Debits</th>
                            <th style="padding:8px 16px 8px 8px;text-align:right;font-weight:500;color:rgb(55 65 81);white-space:nowrap">Our cost</th>
                            <th style="padding:8px 16px 8px 8px;text-align:right;font-weight:500;color:rgb(55 65 81);white-space:nowrap">Billed</th>
                            <th style="padding:8px 8px;text-align:right;font-weight:500;color:rgb(55 65 81);white-space:nowrap">Margin</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr style="border-bottom:1px solid rgb(243 244 246)">
                                <td style="padding:8px 16px 8px 8px">
                                    <div style="color:rgb(17 24 39)">{{ $row['name'] }}</div>
                                    <div style="font-size:0.75rem;color:rgb(107 114 128)">{{ $row['email'] }}</div>
                                </td>
                                <td style="padding:8px 16px 8px 8px;text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;color:rgb(55 65 81)">{{ number_format($row['debit_count']) }}</td>
                                <td style="padding:8px 16px 8px 8px;text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;color:rgb(217 119 6)">&euro;{{ number_format($row['provider_cost'], 4) }}</td>
                                <td style="padding:8px 16px 8px 8px;text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;color:rgb(2 132 199)">&euro;{{ number_format($row['user_cost'], 4) }}</td>
                                <td style="padding:8px 8px;text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;color:{{ $row['margin'] >= 0 ? 'rgb(5 150 105)' : 'rgb(225 29 72)' }}">&euro;{{ number_format($row['margin'], 4) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
