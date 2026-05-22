{{--
    Small topbar icon that drops the user back to the chat UI from any
    Filament panel (admin or user). Rendered via PanelsRenderHook::USER_MENU_BEFORE
    on both panel providers, so it sits immediately to the left of the avatar
    in the top-right of every page.

    Tooltip explains the action; the icon alone keeps the topbar quiet on
    smaller widths.
--}}
<a
    href="{{ route('chats.index') }}"
    title="Back to chats"
    aria-label="Back to chats"
    class="fi-icon-btn relative flex items-center justify-center rounded-lg outline-none transition duration-75 focus-visible:ring-2 fi-color-gray fi-icon-btn-color-gray text-gray-500 hover:text-gray-700 focus-visible:ring-gray-400 dark:text-gray-400 dark:hover:text-gray-300 dark:focus-visible:ring-gray-500 h-9 w-9"
>
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-5 w-5">
        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.76c0 1.6 1.123 2.994 2.707 3.227 1.068.157 2.148.279 3.238.364.466.037.893.281 1.153.671L12 21l2.652-3.978c.26-.39.687-.634 1.153-.67 1.09-.086 2.17-.208 3.238-.365 1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0 0 12 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018Z" />
    </svg>
</a>
