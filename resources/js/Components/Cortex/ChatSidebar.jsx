import { Link, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import ThemeToggle from './ThemeToggle';
import { useT } from '@/i18n/I18nProvider';

/**
 * App sidebar — chat list grouped by "Today / Yesterday / Earlier", new-chat
 * button, account footer with theme + sign-out. Collapses to a 56px rail on
 * narrow screens so the chat content gets the breathing room.
 */
export default function ChatSidebar({ chats, activeId }) {
    const { t } = useT();
    const user = usePage().props.auth?.user;
    const [collapsed, setCollapsed] = useState(false);

    const groups = useMemo(() => groupByDay(chats ?? []), [chats]);

    return (
        <aside
            className={`relative flex shrink-0 flex-col border-r border-ink-200/60 bg-white text-ink-700 transition-[width] duration-200 ease-snap dark:border-ink-800/60 dark:bg-ink-900 dark:text-ink-200 ${
                collapsed ? 'w-14' : 'w-64'
            }`}
        >
            <div className="flex items-center justify-between gap-2 px-3 py-3">
                <Link
                    href="/chats"
                    className={`flex items-center gap-2 overflow-hidden text-sm font-semibold tracking-tight ${
                        collapsed ? 'justify-center' : ''
                    }`}
                >
                    <BrandMark className="h-6 w-6 shrink-0" />
                    {!collapsed && <span className="text-ink-900 dark:text-ink-50">Cortex</span>}
                </Link>
                <button
                    onClick={() => setCollapsed((v) => !v)}
                    className="rounded-lg p-1 text-ink-500 transition-colors hover:bg-ink-100 hover:text-ink-700 dark:hover:bg-ink-800 dark:hover:text-ink-200"
                    title={collapsed ? 'Expand' : 'Collapse'}
                >
                    <ChevronIcon className={`h-4 w-4 transition-transform ${collapsed ? 'rotate-180' : ''}`} />
                </button>
            </div>

            <div className="px-2 pb-2">
                <Link
                    href="/chats"
                    className={`flex items-center gap-2 rounded-xl bg-cortex-600 px-3 py-2 text-sm font-medium text-white shadow-soft transition-colors hover:bg-cortex-500 ${
                        collapsed ? 'justify-center' : ''
                    }`}
                >
                    <PlusIcon className="h-4 w-4 shrink-0" />
                    {!collapsed && <span>{t('sidebar.newChat') || 'New boardroom'}</span>}
                </Link>
            </div>

            <nav className="flex-1 overflow-y-auto px-1.5 pb-2">
                {Object.entries(groups).map(([label, items]) => (
                    <div key={label} className="mt-2">
                        {!collapsed && (
                            <div className="px-2.5 pb-1 text-[11px] font-medium uppercase tracking-wider text-ink-400 dark:text-ink-500">
                                {label}
                            </div>
                        )}
                        <div className="space-y-0.5">
                            {items.map((c) => (
                                <ChatItem key={c.id} chat={c} active={c.id === activeId} collapsed={collapsed} />
                            ))}
                        </div>
                    </div>
                ))}
                {chats?.length === 0 && !collapsed && (
                    <div className="mt-6 px-3 text-center text-xs text-ink-400">
                        {t('sidebar.empty') || 'No discussions yet'}
                    </div>
                )}
            </nav>

            <div className="border-t border-ink-200/60 px-2 py-2 dark:border-ink-800/60">
                {!collapsed && (
                    <div className="mb-2 flex items-center justify-between px-1">
                        <ThemeToggle />
                        <a
                            href="/admin"
                            className="rounded-lg px-2 py-1 text-[11px] text-ink-400 transition-colors hover:bg-ink-100 hover:text-ink-700 dark:hover:bg-ink-800 dark:hover:text-ink-200"
                        >
                            Admin
                        </a>
                    </div>
                )}
                <a
                    href="/user"
                    className={`flex items-center gap-2 rounded-xl px-2 py-1.5 text-sm text-ink-700 transition-colors hover:bg-ink-100 dark:text-ink-200 dark:hover:bg-ink-800 ${
                        collapsed ? 'justify-center' : ''
                    }`}
                >
                    <Avatar name={user?.name} />
                    {!collapsed && (
                        <span className="min-w-0 flex-1 truncate text-left">
                            <span className="block truncate text-sm">{user?.name}</span>
                            <span className="block truncate text-[11px] text-ink-400">{user?.email}</span>
                        </span>
                    )}
                </a>
            </div>
        </aside>
    );
}

function ChatItem({ chat, active, collapsed }) {
    return (
        <Link
            href={`/chats/${chat.id}`}
            className={`group flex items-center gap-2 rounded-xl px-2.5 py-2 text-sm transition-colors ${
                active
                    ? 'bg-cortex-50 text-cortex-700 ring-1 ring-cortex-100 dark:bg-cortex-950/60 dark:text-cortex-200 dark:ring-cortex-900/60'
                    : 'text-ink-700 hover:bg-ink-100 dark:text-ink-200 dark:hover:bg-ink-800/60'
            }`}
            title={chat.title}
        >
            <ChatDot active={active} status={chat.status} />
            {!collapsed && (
                <span className="min-w-0 flex-1 truncate">{chat.title || 'Untitled'}</span>
            )}
        </Link>
    );
}

function ChatDot({ active, status }) {
    const color = status === 'active' ? 'bg-emerald-500' : 'bg-ink-300 dark:bg-ink-600';
    return <span className={`h-1.5 w-1.5 shrink-0 rounded-full ${active ? 'bg-cortex-500' : color}`} />;
}

function Avatar({ name }) {
    const initials = (name || '?').split(/\s+/).map((p) => p[0]).slice(0, 2).join('').toUpperCase();
    return (
        <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-cortex-100 text-[11px] font-semibold text-cortex-700 dark:bg-cortex-950 dark:text-cortex-200">
            {initials}
        </span>
    );
}

function BrandMark({ className }) {
    // A simple monogram — three stacked nodes, gradient-filled. Distinct from
    // Claude's spark and GPT's hex, hints at the multi-persona core.
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <defs>
                <linearGradient id="cortexBrand" x1="0" y1="0" x2="24" y2="24" gradientUnits="userSpaceOnUse">
                    <stop offset="0" stopColor="#4c5fe6" />
                    <stop offset="1" stopColor="#7c3aed" />
                </linearGradient>
            </defs>
            <circle cx="6"  cy="8"  r="3" fill="url(#cortexBrand)" opacity="0.85" />
            <circle cx="18" cy="8"  r="3" fill="url(#cortexBrand)" opacity="0.65" />
            <circle cx="12" cy="17" r="3" fill="url(#cortexBrand)" />
        </svg>
    );
}

function PlusIcon({ className }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M12 5v14M5 12h14" />
        </svg>
    );
}

function ChevronIcon({ className }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M15 18l-6-6 6-6" />
        </svg>
    );
}

function groupByDay(chats) {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const yesterday = new Date(today);
    yesterday.setDate(yesterday.getDate() - 1);

    const out = { Today: [], Yesterday: [], Earlier: [] };
    for (const chat of chats) {
        const d = chat.updated_at ? new Date(chat.updated_at) : new Date(chat.created_at || Date.now());
        const day = new Date(d);
        day.setHours(0, 0, 0, 0);
        if (day.getTime() === today.getTime()) out.Today.push(chat);
        else if (day.getTime() === yesterday.getTime()) out.Yesterday.push(chat);
        else out.Earlier.push(chat);
    }
    // Drop empty buckets so headings don't dangle.
    return Object.fromEntries(Object.entries(out).filter(([, v]) => v.length > 0));
}
