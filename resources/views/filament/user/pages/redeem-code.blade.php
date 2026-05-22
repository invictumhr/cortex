{{-- User self-service top-up redemption page --}}
<x-filament-panels::page>
    {{ $this->form }}

    <x-filament::section heading="How to top up">
        <div class="space-y-2 text-sm text-gray-700 dark:text-gray-300">
            <p>1. Send the SMS keyword from the pricing page to the short number listed there.</p>
            <p>2. You'll receive a 14-digit code via SMS reply (e.g. <code class="font-mono">1234-5678-9012-34</code>).</p>
            <p>3. Paste it above and hit <strong>Redeem</strong>.</p>
            <p>Credits are added to your wallet instantly. Each code can be used once.</p>
        </div>
    </x-filament::section>
</x-filament-panels::page>
