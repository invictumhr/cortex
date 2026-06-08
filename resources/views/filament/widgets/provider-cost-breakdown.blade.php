{{-- ProviderCostBreakdownWidget view --}}
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
                            <th style="padding:8px 16px 8px 8px;text-align:left;font-weight:500;color:rgb(55 65 81);white-space:nowrap">Provider</th>
                            <th style="padding:8px 16px 8px 8px;text-align:right;font-weight:500;color:rgb(55 65 81);white-space:nowrap">Debits</th>
                            <th style="padding:8px 16px 8px 8px;text-align:right;font-weight:500;color:rgb(55 65 81);white-space:nowrap">Our cost</th>
                            <th style="padding:8px 16px 8px 8px;text-align:right;font-weight:500;color:rgb(55 65 81);white-space:nowrap">Billed users</th>
                            <th style="padding:8px 8px;text-align:right;font-weight:500;color:rgb(55 65 81);white-space:nowrap">Margin</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr style="border-bottom:1px solid rgb(243 244 246)">
                                <td style="padding:8px 16px 8px 8px;white-space:nowrap">
                                    @if ($row['dashboard_url'])
                                        <a href="{{ $row['dashboard_url'] }}" target="_blank" rel="noopener" style="display:inline-flex;align-items:center;gap:4px;color:rgb(17 24 39);text-decoration:none">
                                            {{ $row['provider'] }}
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="rgb(156 163 175)" style="width:14px;height:14px;flex-shrink:0"><path fill-rule="evenodd" d="M4.25 5.5a.75.75 0 0 0-.75.75v8.5c0 .414.336.75.75.75h8.5a.75.75 0 0 0 .75-.75v-4a.75.75 0 0 1 1.5 0v4A2.25 2.25 0 0 1 12.75 17h-8.5A2.25 2.25 0 0 1 2 14.75v-8.5A2.25 2.25 0 0 1 4.25 4h5a.75.75 0 0 1 0 1.5h-5Zm7.97-2.03a.75.75 0 0 1 1.06 0l3.5 3.5a.75.75 0 0 1-1.06 1.06l-2.22-2.22V11a.75.75 0 0 1-1.5 0V5.81L9.78 8.03a.75.75 0 0 1-1.06-1.06l3.5-3.5Z" clip-rule="evenodd"/></svg>
                                        </a>
                                    @else
                                        <span style="color:rgb(17 24 39)">{{ $row['provider'] }}</span>
                                    @endif
                                </td>
                                <td style="padding:8px 16px 8px 8px;text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;color:rgb(55 65 81)">{{ number_format($row['call_count']) }}</td>
                                <td style="padding:8px 16px 8px 8px;text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;color:rgb(217 119 6)">&euro;{{ number_format($row['provider_cost'], 4) }}</td>
                                <td style="padding:8px 16px 8px 8px;text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;color:rgb(2 132 199)">&euro;{{ number_format($row['user_cost'], 4) }}</td>
                                <td style="padding:8px 8px;text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums;color:{{ $row['margin'] >= 0 ? 'rgb(5 150 105)' : 'rgb(225 29 72)' }}">&euro;{{ number_format($row['margin'], 4) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Quick links to all provider billing dashboards --}}
        <div style="margin-top:12px;padding-top:12px;border-top:1px solid rgb(229 231 235)">
            <p style="font-size:0.75rem;font-weight:500;color:rgb(107 114 128);margin-bottom:8px">Billing dashboards</p>
            <div style="display:flex;flex-wrap:wrap;gap:8px">
                @foreach ($all_providers as $provider)
                    @if ($provider['dashboard_url'])
                        <a
                            href="{{ $provider['dashboard_url'] }}"
                            target="_blank"
                            rel="noopener"
                            style="display:inline-flex;align-items:center;gap:4px;border-radius:6px;padding:4px 8px;font-size:0.75rem;font-weight:500;text-decoration:none;background:rgb(243 244 246);color:{{ $provider['is_active'] ? 'rgb(55 65 81)' : 'rgb(156 163 175)' }}"
                        >
                            {{ $provider['name'] }}
                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" style="width:12px;height:12px;flex-shrink:0"><path fill-rule="evenodd" d="M4.25 5.5a.75.75 0 0 0-.75.75v8.5c0 .414.336.75.75.75h8.5a.75.75 0 0 0 .75-.75v-4a.75.75 0 0 1 1.5 0v4A2.25 2.25 0 0 1 12.75 17h-8.5A2.25 2.25 0 0 1 2 14.75v-8.5A2.25 2.25 0 0 1 4.25 4h5a.75.75 0 0 1 0 1.5h-5Zm7.97-2.03a.75.75 0 0 1 1.06 0l3.5 3.5a.75.75 0 0 1-1.06 1.06l-2.22-2.22V11a.75.75 0 0 1-1.5 0V5.81L9.78 8.03a.75.75 0 0 1-1.06-1.06l3.5-3.5Z" clip-rule="evenodd"/></svg>
                        </a>
                    @endif
                @endforeach
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
