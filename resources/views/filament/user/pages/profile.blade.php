{{-- Profile (my data) editor --}}
<x-filament-panels::page>
    {{ $this->form }}

    <x-filament::section heading="Account info">
        <div class="space-y-3 text-sm text-gray-700 dark:text-gray-300">
            <div class="flex items-center justify-between">
                <span class="text-gray-500 dark:text-gray-400">Member since</span>
                <span class="tabular-nums">{{ auth()->user()->created_at->format('Y-m-d') }}</span>
            </div>
            <div class="flex items-center justify-between">
                <span class="text-gray-500 dark:text-gray-400">Email verified</span>
                <span>{{ auth()->user()->email_verified_at ? auth()->user()->email_verified_at->format('Y-m-d H:i') : 'Not yet' }}</span>
            </div>
            @if(auth()->user()->free_credit_granted_at)
                <div class="flex items-center justify-between">
                    <span class="text-gray-500 dark:text-gray-400">Free credit issued</span>
                    <span>{{ auth()->user()->free_credit_granted_at->format('Y-m-d H:i') }}</span>
                </div>
            @endif
        </div>
    </x-filament::section>
</x-filament-panels::page>
