{{-- Delete account page --}}
<x-filament-panels::page>
    <x-filament::section heading="What will happen">
        <ul class="list-disc space-y-1 pl-5 text-sm text-gray-700 dark:text-gray-300">
            <li>All your chats, messages and synthesis are permanently deleted.</li>
            <li>Your wallet balance is forfeited — top-up codes can't be reversed.</li>
            <li>Active API tokens are revoked immediately.</li>
            <li>Anonymised durable insights captured by the scribe stay in the global knowledge base.</li>
            <li>You will be signed out and the session destroyed.</li>
        </ul>
    </x-filament::section>

    {{ $this->form }}
</x-filament-panels::page>
